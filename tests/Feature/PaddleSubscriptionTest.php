<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentInitiation;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Events\SubscriptionCreated;
use Fomvasss\Billing\Events\SubscriptionRenewed;
use Fomvasss\Billing\Exceptions\NotSupportedException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Paddle-managed subscriptions. Payload shapes follow developer.paddle.com (subscription entity,
 * transaction.completed with subscription_id/billing_period, custom_data copied from the checkout
 * transaction onto the subscription and its renewals) — not yet live-verified.
 */
class PaddleSubscriptionTest extends TestCase
{
    private Price $price;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.paddle.api_key', 'pdl_sdbx_apikey_test');
        $app['config']->set('billing.gateways.paddle.client_token', 'test_client_token');
        $app['config']->set('billing.gateways.paddle.webhook_secret', 'pdl_ntfset_test');
        $app['config']->set('billing.return_urls.success', 'https://example.test/thanks');
        $app['config']->set('billing.return_urls.failed', 'https://example.test/sorry');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $this->price = Price::create([
            'plan_id' => $plan->id,
            'currency' => 'USD',
            'amount' => 2900,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);
    }

    public function test_starting_a_subscription_sends_a_recurring_price_and_both_references(): void
    {
        [$subscription, $payment] = $this->checkout();

        Http::fake(['https://sandbox-api.paddle.com/transactions' => Http::response(['data' => ['id' => 'txn_1', 'checkout' => ['url' => 'https://example.test/pay?_ptxn=txn_1']]])]);

        Billing::startSubscription($payment);

        Http::assertSent(fn ($request) => $request['items'][0]['price']['billing_cycle'] === ['interval' => 'month', 'frequency' => 1]
            && $request['items'][0]['price']['unit_price'] === ['amount' => '2900', 'currency_code' => 'USD']
            && $request['items'][0]['price']['product']['name'] === 'Pro'
            && $request['custom_data'] === ['payment_id' => (string) $payment->id, 'subscription_id' => (string) $subscription->id]);
        $this->assertSame('txn_1', $payment->fresh()->external_id);
    }

    public function test_what_a_paddle_subscription_cannot_bill_is_refused_up_front(): void
    {
        $this->price->update(['trial_days' => 7]);
        [, $payment] = $this->checkout();

        try {
            Billing::startSubscription($payment);
            $this->fail('A trial checkout completes at zero — the payment would read paid for a sum nobody paid.');
        } catch (NotSupportedException) {
        }

        $this->price->update(['trial_days' => 0, 'interval' => 'hour']);
        [, $payment] = $this->checkout();

        $this->expectException(NotSupportedException::class);
        Billing::startSubscription($payment);
    }

    public function test_the_completed_checkout_pays_the_first_payment_and_links_the_subscription_before_the_listener_sees_it(): void
    {
        Event::fake([SubscriptionCreated::class, SubscriptionRenewed::class]);
        [$subscription, $payment] = $this->checkout(['external_id' => 'txn_1']);
        $periodEnd = now()->addMonth()->startOfSecond();

        Http::fake(['https://sandbox-api.paddle.com/subscriptions/sub_1' => Http::response(['data' => $this->entity($subscription)])]);

        $this->post_('transaction.completed', $this->transaction($payment, $subscription, 'txn_1', $periodEnd));

        $fresh = $subscription->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame('sub_1', $fresh->external_id);
        $this->assertSame(SubscriptionStatus::Active, $fresh->status);
        // Paddle's paid period, not our interval: the listener left the linked row alone.
        $this->assertTrue($fresh->current_period_ends_at->equalTo($periodEnd));
        Event::assertDispatchedTimes(SubscriptionCreated::class, 1);
        Event::assertDispatched(SubscriptionRenewed::class, fn (SubscriptionRenewed $e) => $e->previousStatus === SubscriptionStatus::Incomplete);
    }

    public function test_a_renewal_is_a_new_payment_and_leaves_the_checkout_payment_untouched(): void
    {
        Event::fake([PaymentSucceeded::class, SubscriptionRenewed::class]);
        [$subscription, $payment] = $this->checkout(['external_id' => 'txn_1', 'status' => 'paid']);
        $subscription->update(['external_id' => 'sub_1', 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->startOfSecond()]);
        $next = now()->addMonth()->startOfSecond();

        Http::fake(['https://sandbox-api.paddle.com/subscriptions/sub_1' => Http::response(['data' => $this->entity($subscription)])]);

        // Carries the checkout payment's custom_data — Paddle copies it onto every renewal.
        $this->post_('transaction.completed', [...$this->transaction($payment, $subscription, 'txn_2', $next), 'origin' => 'subscription_recurring']);

        $this->assertSame('txn_1', $payment->fresh()->external_id, 'the checkout payment is not overwritten');
        $renewal = Payment::query()->where('external_id', 'txn_2')->first();
        $this->assertNotNull($renewal);
        $this->assertTrue($renewal->payable->is($subscription));
        $this->assertSame(PaymentStatus::Paid, $renewal->status);
        $this->assertSame(PaymentInitiation::Automatic, $renewal->initiation);
        $this->assertSame(2900, $renewal->amount);
        $this->assertSame(145, $renewal->fee);
        $this->assertTrue($subscription->fresh()->current_period_ends_at->equalTo($next));
        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payment->is($renewal));
        Event::assertDispatched(SubscriptionRenewed::class, fn (SubscriptionRenewed $e) => $e->previousStatus === SubscriptionStatus::Active);
    }

    public function test_subscription_events_mirror_the_state_without_moving_the_paid_period(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        $subscription = $this->linked();
        $periodEnd = $subscription->current_period_ends_at;

        // Paddle moves current_billing_period forward before collecting — the paid period must stay.
        $this->post_('subscription.updated', [
            ...$this->entity($subscription),
            'current_billing_period' => ['starts_at' => now()->toIso8601String(), 'ends_at' => now()->addMonths(2)->toIso8601String()],
            'scheduled_change' => ['action' => 'cancel', 'effective_at' => $periodEnd->toIso8601String(), 'resume_at' => null],
        ]);

        $fresh = $subscription->fresh();
        $this->assertTrue($fresh->current_period_ends_at->equalTo($periodEnd));
        $this->assertTrue($fresh->cancels_at->equalTo($periodEnd), 'a cancellation scheduled on Paddle (customer portal, email link) is mirrored');

        $this->post_('subscription.canceled', [...$this->entity($subscription), 'status' => 'canceled', 'canceled_at' => now()->toIso8601String()], occurredAt: now()->addSecond());

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);
    }

    public function test_cancel_at_period_end_is_sent_to_paddle(): void
    {
        $subscription = $this->linked();

        Http::fake(['https://sandbox-api.paddle.com/subscriptions/sub_1/cancel' => Http::response(['data' => [
            ...$this->entity($subscription),
            'scheduled_change' => ['action' => 'cancel', 'effective_at' => $subscription->current_period_ends_at->toIso8601String(), 'resume_at' => null],
        ]])]);

        $subscription->cancel();

        Http::assertSent(fn ($request) => $request['effective_from'] === 'next_billing_period');
        $this->assertTrue($subscription->fresh()->cancels_at->equalTo($subscription->current_period_ends_at));
        $this->assertTrue($subscription->fresh()->isActive());
    }

    public function test_pause_from_the_period_end_and_resume_of_a_merely_scheduled_pause(): void
    {
        $subscription = $this->linked();
        $until = now()->addMonths(2)->startOfSecond();
        $scheduled = [...$this->entity($subscription), 'scheduled_change' => ['action' => 'pause', 'effective_at' => $subscription->current_period_ends_at->toIso8601String(), 'resume_at' => $until->toIso8601String()]];

        Http::fake([
            'https://sandbox-api.paddle.com/subscriptions/sub_1/pause' => Http::response(['data' => $scheduled]),
            'https://sandbox-api.paddle.com/subscriptions/sub_1' => Http::sequence()
                ->push(['data' => $scheduled])                            // GET before resume
                ->push(['data' => $this->entity($subscription)]),        // PATCH scheduled_change: null
        ]);

        $subscription->pause($until);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pause') && $request['effective_from'] === 'next_billing_period' && $request['resume_at'] === $until->toIso8601ZuluString());
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status, 'paused only once Paddle actually pauses it');

        $subscription->fresh()->resume();

        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->data() === ['scheduled_change' => null]);
    }

    public function test_a_plan_swap_goes_to_paddle_on_the_existing_product(): void
    {
        $subscription = $this->linked();
        $newPrice = Price::create(['plan_id' => $this->price->plan_id, 'currency' => 'USD', 'amount' => 4900, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);

        Http::fake(['https://sandbox-api.paddle.com/subscriptions/sub_1' => Http::sequence()
            ->push(['data' => $this->entity($subscription)])
            ->push(['data' => $this->entity($subscription)]),
        ]);

        $subscription->swapPlan($newPrice);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request['proration_billing_mode'] === 'prorated_immediately'
            && $request['items'][0]['price']['product_id'] === 'pro_1'
            && $request['items'][0]['price']['unit_price'] === ['amount' => '4900', 'currency_code' => 'USD']);
        $this->assertSame($newPrice->id, $subscription->fresh()->price_id);
    }

    public function test_a_change_paddle_refuses_surfaces_its_reason(): void
    {
        $subscription = $this->linked();
        $newPrice = Price::create(['plan_id' => $this->price->plan_id, 'currency' => 'USD', 'amount' => 3000, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);

        // Live-verified: a prorated difference below Paddle's minimum charge is refused like this.
        Http::fake(['https://sandbox-api.paddle.com/subscriptions/sub_1' => Http::sequence()
            ->push(['data' => $this->entity($subscription)])
            ->push(['error' => ['code' => 'subscription_update_transaction_balance_less_than_charge_limit', 'detail' => 'Unable to charge less than the minimum']], 400),
        ]);

        try {
            $subscription->swapPlan($newPrice);
            $this->fail('A refused change must not pass silently.');
        } catch (\Fomvasss\Billing\Exceptions\BillingException $exception) {
            $this->assertStringContainsString('subscription_update_transaction_balance_less_than_charge_limit', $exception->getMessage());
        }

        $this->assertSame($this->price->id, $subscription->fresh()->price_id);
    }

    /** @return array{0: Subscription, 1: Payment} */
    private function checkout(array $paymentAttributes = []): array
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $subscription = Subscription::create([
            'status' => SubscriptionStatus::Incomplete,
            'gateway' => 'paddle',
            'price_id' => $this->price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
        $payment = Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'paddle',
            'amount' => 2900,
            'currency' => 'USD',
            'payable_type' => Subscription::class,
            'payable_id' => $subscription->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$paymentAttributes,
        ]);

        return [$subscription, $payment];
    }

    private function linked(): Subscription
    {
        [$subscription] = $this->checkout();
        $subscription->update(['external_id' => 'sub_1', 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addDays(10)->startOfSecond()]);

        return $subscription->fresh();
    }

    private function entity(Subscription $subscription): array
    {
        return [
            'id' => 'sub_1',
            'status' => 'active',
            'currency_code' => 'USD',
            'custom_data' => ['subscription_id' => (string) $subscription->id],
            'current_billing_period' => ['starts_at' => now()->toIso8601String(), 'ends_at' => now()->addMonth()->toIso8601String()],
            'scheduled_change' => null,
            'canceled_at' => null,
            'updated_at' => now()->toIso8601String(),
            'items' => [['price' => ['id' => 'pri_1', 'product_id' => 'pro_1']]],
        ];
    }

    private function transaction(Payment $payment, Subscription $subscription, string $id, \DateTimeInterface $periodEnd): array
    {
        return [
            'id' => $id,
            'status' => 'completed',
            'origin' => 'web',
            'subscription_id' => 'sub_1',
            'currency_code' => 'USD',
            'custom_data' => ['payment_id' => (string) $payment->id, 'subscription_id' => (string) $subscription->id],
            'billing_period' => ['starts_at' => now()->toIso8601String(), 'ends_at' => $periodEnd->format(\DateTimeInterface::RFC3339)],
            'items' => [['quantity' => 1, 'price' => ['unit_price' => ['amount' => '2900', 'currency_code' => 'USD']]]],
            'details' => ['totals' => ['grand_total' => '2900', 'fee' => '145']],
        ];
    }

    private function post_(string $type, array $data, ?\DateTimeInterface $occurredAt = null): void
    {
        static $n = 0;
        $body = json_encode(['event_id' => 'evt_' . ++$n, 'event_type' => $type, 'occurred_at' => ($occurredAt ?? now())->format(\DateTimeInterface::RFC3339_EXTENDED), 'data' => $data]);
        $timestamp = time();

        $this->call('POST', route('billing.webhook', ['gateway' => 'paddle']), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_PADDLE_SIGNATURE' => "ts={$timestamp};h1=" . hash_hmac('sha256', "{$timestamp}:{$body}", 'pdl_ntfset_test')], $body)
            ->assertOk();
    }
}
