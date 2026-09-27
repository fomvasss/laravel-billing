<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Events\PaymentSucceeded;
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
            && $request['subscribed_events'] === ['transaction.completed', 'transaction.canceled']);
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
