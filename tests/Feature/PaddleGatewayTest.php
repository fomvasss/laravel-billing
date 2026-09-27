<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentRefunded;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Support\Money;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Payloads follow developer.paddle.com's documented shapes (api-reference/transactions,
 * webhooks/transactions/transaction-completed) — not yet live-verified against the sandbox.
 */
class PaddleGatewayTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.paddle.api_key', 'pdl_sdbx_apikey_test');
        $app['config']->set('billing.gateways.paddle.client_token', 'test_client_token');
        $app['config']->set('billing.gateways.paddle.webhook_secret', 'pdl_ntfset_test');
        $app['config']->set('billing.return_urls.success', 'https://example.test/thanks');
        $app['config']->set('billing.return_urls.failed', 'https://example.test/sorry');
    }

    public function test_charge_creates_a_non_catalog_transaction(): void
    {
        $payment = $this->pendingPayment();
        // What Paddle returns: the account's default payment link — this page — plus ?_ptxn=.
        $checkoutPage = route('billing.paddle.checkout');

        Http::fake([
            'https://sandbox-api.paddle.com/transactions' => Http::response(['data' => [
                'id' => 'txn_1',
                'status' => 'draft',
                'checkout' => ['url' => "{$checkoutPage}?_ptxn=txn_1"],
            ]]),
        ]);

        $result = Billing::charge($payment);

        $this->assertSame("{$checkoutPage}?_ptxn=txn_1", $result->url);
        $this->assertSame('txn_1', $payment->fresh()->external_id);
        $this->assertSame("{$checkoutPage}?_ptxn=txn_1", $payment->fresh()->payment_url);
        $this->assertTrue($result->expiresAt > now()->addMinutes(1400));

        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox-api.paddle.com/transactions'
            && $request->hasHeader('Paddle-Version', '1')
            && $request->hasHeader('Authorization', 'Bearer pdl_sdbx_apikey_test')
            && $request['currency_code'] === 'UAH'
            && $request['custom_data'] === ['payment_id' => (string) $payment->id]
            // No checkout.url override — Paddle refuses one on a domain without Website approval.
            && ! isset($request['checkout'])
            && $request['items'][0]['quantity'] === 1
            // minor units as a string, exactly as Paddle wants them — no /100
            && $request['items'][0]['price']['unit_price'] === ['amount' => '10000', 'currency_code' => 'UAH']
            && $request['items'][0]['price']['product']['tax_category'] === 'standard');
    }

    public function test_a_live_key_goes_to_the_live_api(): void
    {
        config()->set('billing.gateways.paddle.api_key', 'pdl_live_apikey_test');

        Http::fake(['https://api.paddle.com/transactions' => Http::response(['data' => ['id' => 'txn_1', 'checkout' => ['url' => 'https://example.test/pay?_ptxn=txn_1']]])]);

        Billing::charge($this->pendingPayment());

        Http::assertSent(fn ($request) => $request->url() === 'https://api.paddle.com/transactions');
    }

    public function test_receipt_items_become_one_line_each(): void
    {
        $payment = $this->pendingPayment();

        Http::fake(['https://sandbox-api.paddle.com/transactions' => Http::response(['data' => ['id' => 'txn_1', 'checkout' => ['url' => 'https://example.test/pay?_ptxn=txn_1']]])]);

        Billing::charge($payment, new \Fomvasss\Billing\DTO\ChargeOptions(receiptItems: [
            ['name' => 'Plan', 'qty' => 1, 'unitAmount' => 8000],
            ['name' => 'Seat', 'qty' => 2, 'unitAmount' => 1000],
        ]));

        Http::assertSent(fn ($request) => count($request['items']) === 2
            && $request['items'][1]['quantity'] === 2
            && $request['items'][1]['price']['unit_price']['amount'] === '1000'
            // the checkout's quantity stepper is pinned, or the customer could pay for 3 seats
            && $request['items'][1]['price']['quantity'] === ['minimum' => 2, 'maximum' => 2]
            && $request['items'][1]['price']['product']['name'] === 'Seat');
    }

    public function test_a_reissue_cancels_the_previous_transaction_so_it_cannot_be_paid_next_to_the_new_one(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_old', 'status' => 'canceled']);

        Http::fake([
            'https://sandbox-api.paddle.com/transactions/txn_old' => Http::response(['data' => ['id' => 'txn_old', 'status' => 'canceled']]),
            'https://sandbox-api.paddle.com/transactions' => Http::response(['data' => ['id' => 'txn_new', 'checkout' => ['url' => 'https://example.test/pay?_ptxn=txn_new']]]),
        ]);

        Billing::charge($payment);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://sandbox-api.paddle.com/transactions/txn_old'
            && $request['status'] === 'canceled');
        $this->assertSame('txn_new', $payment->fresh()->external_id);
    }

    public function test_a_failed_cancel_of_the_previous_transaction_does_not_block_the_reissue(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_old']);

        Http::fake([
            'https://sandbox-api.paddle.com/transactions/txn_old' => Http::response(['error' => ['code' => 'transaction_immutable']], 400),
            'https://sandbox-api.paddle.com/transactions' => Http::response(['data' => ['id' => 'txn_new', 'checkout' => ['url' => 'https://example.test/pay?_ptxn=txn_new']]]),
        ]);

        Billing::charge($payment);

        $this->assertSame('txn_new', $payment->fresh()->external_id);
    }

    public function test_transaction_completed_marks_the_payment_paid_with_paddles_fee(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $payment = $this->pendingPayment(['external_id' => 'txn_1']);

        $result = Billing::driver('paddle')->handleWebhook($this->webhook('transaction.completed', $this->transaction($payment)));

        $this->assertSame(WebhookEventType::Payment, $result->type);
        $this->assertSame('succeeded', $result->status);
        $this->assertSame('txn_1', $result->externalId);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(530, $payment->fresh()->fee);
    }

    public function test_the_paid_amount_is_checked_against_the_issued_unit_price_not_the_tax_inclusive_total(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_1']);

        // Exclusive tax: the customer paid 12000 (10000 + 2000 tax) for a 10000 payment — a match.
        $transaction = $this->transaction($payment);
        $transaction['details']['totals'] = [...$transaction['details']['totals'], 'tax' => '2000', 'total' => '12000', 'grand_total' => '12000'];

        Billing::driver('paddle')->handleWebhook($this->webhook('transaction.completed', $transaction));

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_a_stale_transaction_issued_for_another_amount_does_not_mark_the_payment_paid(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_1']);
        $transaction = $this->transaction($payment);
        $transaction['items'][0]['price']['unit_price']['amount'] = '5000';

        $result = Billing::driver('paddle')->handleWebhook($this->webhook('transaction.completed', $transaction));

        $this->assertSame(WebhookEventType::Ignored, $result->type);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_a_transaction_without_our_payment_id_is_ignored(): void
    {
        $transaction = $this->transaction($this->pendingPayment());
        $transaction['custom_data'] = ['order' => 'someone-elses-123'];

        $result = Billing::driver('paddle')->handleWebhook($this->webhook('transaction.completed', $transaction));

        $this->assertSame(WebhookEventType::Ignored, $result->type);
    }

    public function test_a_declined_attempt_is_not_an_outcome_while_the_checkout_is_open(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_1']);

        $result = Billing::driver('paddle')->handleWebhook($this->webhook('transaction.payment_failed', $this->transaction($payment, 'ready')));

        $this->assertSame(WebhookEventType::Ignored, $result->type);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_canceling_the_current_transaction_cancels_the_payment(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_1']);

        $result = Billing::driver('paddle')->handleWebhook($this->webhook('transaction.canceled', $this->transaction($payment, 'canceled')));

        $this->assertSame('canceled', $result->status);
        $this->assertSame(PaymentStatus::Canceled, $payment->fresh()->status);
    }

    public function test_the_cancel_echo_of_a_replaced_transaction_leaves_the_reissued_payment_alone(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_new']);
        $old = [...$this->transaction($payment, 'canceled'), 'id' => 'txn_old'];

        $result = Billing::driver('paddle')->handleWebhook($this->webhook('transaction.canceled', $old));

        $this->assertSame(WebhookEventType::Ignored, $result->type);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_check_status_marks_a_completed_transaction_paid(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_1']);

        Http::fake(['https://sandbox-api.paddle.com/transactions/txn_1' => Http::response(['data' => $this->transaction($payment)])]);

        $result = Billing::driver('paddle')->checkStatus($payment);

        $this->assertSame('succeeded', $result->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_check_status_leaves_an_open_checkout_alone_while_its_link_is_alive(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_1', 'payment_url_expires_at' => now()->addHour()]);

        Http::fake(['https://sandbox-api.paddle.com/transactions/txn_1' => Http::response(['data' => $this->transaction($payment, 'ready')])]);

        $this->assertSame(WebhookEventType::Ignored, Billing::driver('paddle')->checkStatus($payment)->type);
        Http::assertSentCount(1);
    }

    public function test_check_status_cancels_an_open_checkout_whose_link_expired(): void
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_1', 'payment_url_expires_at' => now()->subMinute()]);

        Http::fake(['https://sandbox-api.paddle.com/transactions/txn_1' => Http::sequence()
            ->push(['data' => $this->transaction($payment, 'ready')])
            ->push(['data' => $this->transaction($payment, 'canceled')]),
        ]);

        $result = Billing::driver('paddle')->checkStatus($payment);

        $this->assertSame('canceled', $result->status);
        $this->assertSame(PaymentStatus::Canceled, $payment->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['status'] === 'canceled');
    }

    public function test_health_check_probes_event_types(): void
    {
        Http::fake(['https://sandbox-api.paddle.com/event-types' => Http::response(['data' => []])]);

        $health = Billing::health('paddle');

        $this->assertTrue($health->ok);
        $this->assertSame('sandbox', $health->message);
    }

    public function test_a_signed_webhook_is_accepted(): void
    {
        $body = json_encode(['event_type' => 'transaction.created', 'data' => ['id' => 'txn_1']]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}:{$body}", 'pdl_ntfset_test');

        $this->postSigned($body, "ts={$timestamp};h1={$signature}")->assertOk();
    }

    public function test_any_matching_signature_is_accepted_during_secret_rotation(): void
    {
        $body = json_encode(['event_type' => 'transaction.created', 'data' => ['id' => 'txn_1']]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}:{$body}", 'pdl_ntfset_test');

        $this->postSigned($body, "ts={$timestamp};h1=" . str_repeat('0', 64) . ";h1={$signature}")->assertOk();
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $body = json_encode(['event_type' => 'transaction.completed', 'data' => ['id' => 'txn_1']]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}:{$body}", 'pdl_ntfset_test');

        $this->postSigned(str_replace('txn_1', 'txn_2', $body), "ts={$timestamp};h1={$signature}")->assertForbidden();
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $body = json_encode(['event_type' => 'transaction.completed']);
        $timestamp = time() - 600;
        $signature = hash_hmac('sha256', "{$timestamp}:{$body}", 'pdl_ntfset_test');

        $this->postSigned($body, "ts={$timestamp};h1={$signature}")->assertForbidden();
    }

    public function test_an_unconfigured_secret_fails_closed(): void
    {
        config()->set('billing.gateways.paddle.webhook_secret', null);

        $body = json_encode(['event_type' => 'transaction.completed']);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}:{$body}", '');

        $this->postSigned($body, "ts={$timestamp};h1={$signature}")->assertForbidden();
    }

    public function test_the_checkout_page_initializes_paddle_js_with_the_payments_return_urls(): void
    {
        $payment = $this->pendingPayment();

        Http::fake(['https://sandbox-api.paddle.com/transactions' => Http::response(['data' => ['id' => 'txn_1', 'checkout' => ['url' => 'https://example.test/pay?_ptxn=txn_1']]])]);
        Billing::charge($payment);

        $this->get(route('billing.paddle.checkout') . '?_ptxn=txn_1')
            ->assertOk()
            ->assertSee('https://cdn.paddle.com/paddle/v2/paddle.js', false)
            ->assertSee("Paddle.Environment.set('sandbox')", false)
            ->assertSee('"test_client_token"', false)
            ->assertSee(json_encode(route('billing.return', ['payment' => $payment, 'outcome' => 'success'])), false)
            ->assertSee(json_encode(route('billing.return', ['payment' => $payment, 'outcome' => 'failed'])), false);
    }

    public function test_the_checkout_page_opens_a_transaction_that_is_not_one_of_our_payments(): void
    {
        config()->set('billing.gateways.paddle.client_token', 'live_client_token');

        $this->get(route('billing.paddle.checkout') . '?_ptxn=txn_card_update')
            ->assertOk()
            ->assertSee('"live_client_token"', false)
            ->assertDontSee("Paddle.Environment.set('sandbox')", false);
    }

    public function test_register_webhook_creates_a_destination_and_prints_its_secret(): void
    {
        Http::fake([
            'https://sandbox-api.paddle.com/notification-settings' => Http::sequence()
                ->push(['data' => [], 'meta' => ['pagination' => ['has_more' => false]]])
                ->push(['data' => ['id' => 'ntfset_1', 'endpoint_secret_key' => 'pdl_ntfset_new']]),
        ]);

        $this->artisan('billing:paddle-register-webhook')
            ->expectsOutputToContain('Registered ntfset_1')
            ->expectsOutputToContain('PADDLE_WEBHOOK_SECRET=pdl_ntfset_new')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['destination'] === route('billing.webhook', ['gateway' => 'paddle'])
            && $request['type'] === 'url'
            && $request['subscribed_events'] === ['transaction.completed', 'transaction.canceled', 'adjustment.created', 'adjustment.updated']);
    }

    public function test_register_webhook_updates_an_existing_destination_instead_of_duplicating_it(): void
    {
        $url = route('billing.webhook', ['gateway' => 'paddle']);

        Http::fake([
            'https://sandbox-api.paddle.com/notification-settings' => Http::response(['data' => [['id' => 'ntfset_1', 'destination' => $url]], 'meta' => ['pagination' => ['has_more' => false]]]),
            'https://sandbox-api.paddle.com/notification-settings/ntfset_1' => Http::response(['data' => ['id' => 'ntfset_1', 'endpoint_secret_key' => 'pdl_ntfset_kept']]),
        ]);

        $this->artisan('billing:paddle-register-webhook')
            ->expectsOutputToContain('Updated ntfset_1')
            ->expectsOutputToContain('PADDLE_WEBHOOK_SECRET=pdl_ntfset_kept')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_a_full_refund_awaiting_approval_reserves_the_amount_without_announcing_a_refund(): void
    {
        Event::fake([PaymentRefunded::class]);
        $payment = $this->paidPayment();

        Http::fake(['https://sandbox-api.paddle.com/adjustments' => Http::response(['data' => $this->adjustment('pending_approval', type: 'full')])]);

        $refund = Billing::refund($payment);

        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox-api.paddle.com/adjustments'
            && $request['action'] === 'refund'
            && $request['type'] === 'full'
            && $request['transaction_id'] === 'txn_1'
            && ! isset($request['items']));

        $this->assertSame(PaymentStatus::Pending, $refund->status);
        $this->assertSame('adj_1', $refund->external_id);
        $this->assertSame(0, $payment->refundedAmount(), 'no money has moved yet');
        $this->assertSame(0, $payment->refundableRemainder(), 'but the amount is spoken for');
        Event::assertNotDispatched(PaymentRefunded::class);

        $this->expectException(\Fomvasss\Billing\Exceptions\BillingException::class);
        Billing::refund($payment, new Money(100, 'UAH'));
    }

    public function test_a_refund_paddle_approves_on_the_spot_is_final_and_its_webhook_echo_is_dropped(): void
    {
        Event::fake([PaymentRefunded::class]);
        $payment = $this->paidPayment();

        Http::fake(['https://sandbox-api.paddle.com/adjustments' => Http::response(['data' => $this->adjustment('approved', type: 'full')])]);

        $refund = Billing::refund($payment);

        $this->assertSame(PaymentStatus::Paid, $refund->status);
        $this->assertSame(10000, $payment->refundedAmount());

        $this->postAdjustment('adjustment.created', $this->adjustment('approved', type: 'full'));

        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        $this->assertSame(1, $payment->refunds()->count());
    }

    public function test_a_partial_refund_is_scaled_to_what_was_paid_and_spread_over_lines_with_room_left(): void
    {
        $payment = $this->paidPayment();

        // Exclusive tax: 10000 issued, 12000 paid. Line 1 (6000) already gave 3000 to an earlier
        // refund, so a 5000 refund — 6000 in paid terms — takes the 3000 left there and 3000 from
        // line 2.
        Http::fake([
            'https://sandbox-api.paddle.com/transactions/txn_1*' => Http::response(['data' => [
                'id' => 'txn_1',
                'details' => [
                    'totals' => ['grand_total' => '12000'],
                    'line_items' => [
                        ['id' => 'txnitm_1', 'totals' => ['total' => '6000']],
                        ['id' => 'txnitm_2', 'totals' => ['total' => '6000']],
                    ],
                ],
                'adjustments' => [
                    ['action' => 'refund', 'status' => 'approved', 'items' => [['item_id' => 'txnitm_1', 'totals' => ['total' => '3000']]]],
                    ['action' => 'refund', 'status' => 'rejected', 'items' => [['item_id' => 'txnitm_2', 'totals' => ['total' => '6000']]]],
                ],
            ]]),
            'https://sandbox-api.paddle.com/adjustments' => Http::response(['data' => $this->adjustment('pending_approval')]),
        ]);

        Billing::refund($payment, new Money(5000, 'UAH'));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['type'] === 'partial'
            && $request['items'] === [
                ['item_id' => 'txnitm_1', 'type' => 'partial', 'amount' => '3000'],
                ['item_id' => 'txnitm_2', 'type' => 'partial', 'amount' => '3000'],
            ]);
    }

    public function test_approval_completes_our_pending_refund_and_announces_it_once(): void
    {
        Event::fake([PaymentRefunded::class]);
        $payment = $this->paidPayment();
        $refund = Payment::recordRefundOf($payment, new Money(10000, 'UAH'), 'adj_1', [], PaymentStatus::Pending);

        $this->postAdjustment('adjustment.updated', $this->adjustment('approved', type: 'full'));
        $this->postAdjustment('adjustment.updated', $this->adjustment('approved', type: 'full'));

        $this->assertSame(PaymentStatus::Paid, $refund->fresh()->status);
        $this->assertSame(10000, $payment->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    public function test_a_rejected_refund_fails_quietly_and_frees_the_amount(): void
    {
        Event::fake([PaymentRefunded::class, PaymentFailed::class]);
        $payment = $this->paidPayment();
        $refund = Payment::recordRefundOf($payment, new Money(10000, 'UAH'), 'adj_1', [], PaymentStatus::Pending);

        $this->postAdjustment('adjustment.updated', $this->adjustment('rejected', type: 'full'));

        $this->assertSame(PaymentStatus::Failed, $refund->fresh()->status);
        $this->assertSame(10000, $payment->refundableRemainder());
        Event::assertNotDispatched(PaymentRefunded::class);
        // PaymentFailed on a renewal's refund row would put its subscription into dunning.
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_a_full_refund_from_the_paddle_dashboard_is_recorded_once_approved(): void
    {
        Event::fake([PaymentRefunded::class]);
        $payment = $this->paidPayment();

        $this->postAdjustment('adjustment.created', $this->adjustment('pending_approval', type: 'full'));
        $this->assertSame(0, $payment->refunds()->count(), 'nothing is recorded before Paddle approves it');

        $this->postAdjustment('adjustment.updated', $this->adjustment('approved', type: 'full'));

        $this->assertSame(10000, $payment->refundedAmount());
        $this->assertSame('adj_1', $payment->refunds()->first()->external_id);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    public function test_a_partial_dashboard_refund_is_scaled_back_to_this_payments_terms(): void
    {
        $payment = $this->paidPayment();

        // 3000 of a 12000 grand total (exclusive tax on a 10000 payment) is a quarter: 2500.
        Http::fake(['https://sandbox-api.paddle.com/transactions/txn_1' => Http::response(['data' => ['id' => 'txn_1', 'details' => ['totals' => ['grand_total' => '12000']]]])]);

        $this->postAdjustment('adjustment.created', [...$this->adjustment('approved'), 'totals' => ['subtotal' => '2500', 'tax' => '500', 'total' => '3000']]);

        $this->assertSame(2500, $payment->refundedAmount());
    }

    public function test_an_adjustment_for_a_transaction_we_do_not_know_is_ignored(): void
    {
        $payment = $this->paidPayment();

        $this->postAdjustment('adjustment.created', [...$this->adjustment('approved', type: 'full'), 'transaction_id' => 'txn_foreign']);

        $this->assertSame(0, $payment->refunds()->count());
    }

    public function test_reconciliation_settles_a_pending_refund_whose_approval_webhook_was_lost(): void
    {
        Event::fake([PaymentRefunded::class]);
        $payment = $this->paidPayment();
        $refund = Payment::recordRefundOf($payment, new Money(10000, 'UAH'), 'adj_1', [], PaymentStatus::Pending);
        $refund->forceFill(['created_at' => now()->subHours(2)])->save();

        Http::fake(['https://sandbox-api.paddle.com/adjustments*' => Http::response(['data' => [$this->adjustment('approved', type: 'full')]])]);

        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/adjustments?id=adj_1'));
        $this->assertSame(PaymentStatus::Paid, $refund->fresh()->status);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    private function postAdjustment(string $type, array $adjustment): void
    {
        $body = json_encode(['event_id' => 'evt_' . $type, 'event_type' => $type, 'occurred_at' => now()->toIso8601String(), 'data' => $adjustment]);
        $timestamp = time();

        $this->postSigned($body, "ts={$timestamp};h1=" . hash_hmac('sha256', "{$timestamp}:{$body}", 'pdl_ntfset_test'))->assertOk();
    }

    private function adjustment(string $status, string $type = 'partial'): array
    {
        return [
            'id' => 'adj_1',
            'action' => 'refund',
            'type' => $type,
            'transaction_id' => 'txn_1',
            'status' => $status,
            'currency_code' => 'UAH',
            'totals' => ['subtotal' => '10000', 'tax' => '0', 'total' => '10000'],
        ];
    }

    private function paidPayment(): Payment
    {
        $payment = $this->pendingPayment(['external_id' => 'txn_1']);
        $payment->transitionTo(PaymentStatus::Paid);

        return $payment;
    }

    private function postSigned(string $body, string $signature): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', route('billing.webhook', ['gateway' => 'paddle']), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_PADDLE_SIGNATURE' => $signature], $body);
    }

    private function webhook(string $type, array $transaction): BillingWebhookCall
    {
        return new BillingWebhookCall(['name' => 'paddle', 'payload' => [
            'event_id' => 'evt_1',
            'event_type' => $type,
            'occurred_at' => now()->toIso8601String(),
            'data' => $transaction,
        ]]);
    }

    private function transaction(Payment $payment, string $status = 'completed'): array
    {
        return [
            'id' => 'txn_1',
            'status' => $status,
            'currency_code' => 'UAH',
            'custom_data' => ['payment_id' => (string) $payment->id],
            'items' => [[
                'quantity' => 1,
                'price' => ['unit_price' => ['amount' => (string) $payment->amount, 'currency_code' => 'UAH'], 'tax_mode' => 'account_setting'],
            ]],
            'details' => ['totals' => [
                'subtotal' => (string) $payment->amount,
                'tax' => '0',
                'total' => (string) $payment->amount,
                'grand_total' => (string) $payment->amount,
                'fee' => $status === 'completed' ? '530' : null,
                'earnings' => $status === 'completed' ? (string) ($payment->amount - 530) : null,
                'currency_code' => 'UAH',
            ]],
        ];
    }

    private function pendingPayment(array $attributes = []): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'paddle',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
