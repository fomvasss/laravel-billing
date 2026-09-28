<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionAccessSuspended;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Events\SubscriptionCreated;
use Fomvasss\Billing\Events\SubscriptionPaused;
use Fomvasss\Billing\Events\SubscriptionPaymentFailed;
use Fomvasss\Billing\Events\SubscriptionRenewed;
use Fomvasss\Billing\Events\SubscriptionResumed;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Exceptions\NotSupportedException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Gateways\Fake\FakeSignatureValidator;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\FakeProviderGateway;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * The gateway-agnostic half of provider-managed subscriptions: the provider owns renewals, dunning,
 * pause and cancellation; the row mirrors what the provider reports (applyProviderSnapshot()) and
 * forwards the consumer's own cancel/pause/resume/swap. Driven through FakeProviderGateway, whose
 * webhook payload is the snapshot itself.
 */
class ProviderSubscriptionSyncTest extends TestCase
{
    private Price $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(BillingManager::class)
            ->extend('provider', FakeProviderGateway::class)
            ->registerWebhook('provider', FakeSignatureValidator::class);

        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $this->price = Price::create([
            'plan_id' => $plan->id,
            'currency' => 'USD',
            'amount' => 2900,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
            'included_units' => 100,
        ]);
    }

    public function test_start_subscription_opens_a_checkout_and_leaves_the_row_waiting_for_its_link(): void
    {
        $subscription = $this->subscription();
        $payment = $this->firstPayment($subscription);

        $result = Billing::startSubscription($payment);

        $this->assertSame("https://provider.test/subscribe/{$payment->id}", $result->url);
        $this->assertSame($result->url, $payment->fresh()->payment_url);
        $this->assertSame(SubscriptionStatus::Incomplete, $subscription->fresh()->status);
        $this->assertFalse($subscription->fresh()->isProviderManaged(), 'linked only when the provider reports its id');
    }

    public function test_start_subscription_needs_a_subscription_payable_and_a_capable_gateway(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $orderPayment = Payment::create(['status' => 'pending', 'type' => 'charge', 'gateway' => 'provider', 'amount' => 2900, 'currency' => 'USD',
            'payable_type' => TestUser::class, 'payable_id' => $user->id, 'billable_type' => TestUser::class, 'billable_id' => $user->id]);

        try {
            Billing::startSubscription($orderPayment);
            $this->fail('A payment for something other than a subscription has nothing to start.');
        } catch (BillingException) {
        }

        $stripePayment = $this->firstPayment($this->subscription(['gateway' => 'stripe']));
        $stripePayment->update(['gateway' => 'stripe']);

        $this->expectException(NotSupportedException::class);
        Billing::startSubscription($stripePayment);
    }

    public function test_the_first_snapshot_links_the_row_and_announces_the_start_once(): void
    {
        Event::fake([SubscriptionCreated::class, SubscriptionRenewed::class]);
        $subscription = $this->subscription();

        $event = ['event_id' => 'evt_1', 'subscription_id' => $subscription->id, 'provider_id' => 'sub_1', 'status' => 'active', 'period_ends_at' => now()->addMonth()->toIso8601String()];
        $this->webhook($event);
        $this->webhook($event); // re-delivery

        $fresh = $subscription->fresh();
        $this->assertTrue($fresh->isProviderManaged());
        $this->assertSame('sub_1', $fresh->external_id);
        $this->assertSame(SubscriptionStatus::Active, $fresh->status);
        $this->assertTrue($fresh->isActive());
        Event::assertDispatchedTimes(SubscriptionCreated::class, 1);
        Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
        Event::assertDispatched(SubscriptionRenewed::class, fn (SubscriptionRenewed $e) => $e->previousStatus === SubscriptionStatus::Incomplete);
    }

    public function test_a_provider_renewal_moves_the_period_and_starts_a_fresh_allowance(): void
    {
        Event::fake([SubscriptionRenewed::class]);
        $subscription = $this->linked(['current_usage' => 80, 'period_notices_sent' => ['3 days']]);
        $next = $subscription->current_period_ends_at->copy()->addMonth();

        $this->webhook(['event_id' => 'evt_2', 'provider_id' => 'sub_1', 'status' => 'active', 'period_ends_at' => $next->toIso8601String()]);

        $fresh = $subscription->fresh();
        $this->assertTrue($fresh->current_period_ends_at->equalTo($next));
        $this->assertSame(0.0, $fresh->current_usage);
        $this->assertNull($fresh->period_notices_sent);
        Event::assertDispatched(SubscriptionRenewed::class, fn (SubscriptionRenewed $e) => $e->previousStatus === SubscriptionStatus::Active);
    }

    public function test_every_event_for_one_subscription_is_applied_not_just_the_first(): void
    {
        $subscription = $this->linked();

        $this->webhook(['event_id' => 'evt_a', 'provider_id' => 'sub_1', 'status' => 'past_due']);
        $this->webhook(['event_id' => 'evt_b', 'provider_id' => 'sub_1', 'status' => 'active']);

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
    }

    public function test_an_older_snapshot_arriving_late_does_not_overwrite_a_newer_one(): void
    {
        $subscription = $this->linked();

        $this->webhook(['event_id' => 'evt_new', 'provider_id' => 'sub_1', 'status' => 'canceled', 'occurred_at' => now()->toIso8601String()]);
        $this->webhook(['event_id' => 'evt_old', 'provider_id' => 'sub_1', 'status' => 'active', 'occurred_at' => now()->subMinute()->toIso8601String()]);

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
    }

    public function test_the_package_listener_leaves_a_provider_managed_subscription_to_its_provider(): void
    {
        $subscription = $this->linked();
        $periodEnd = $subscription->current_period_ends_at;
        $payment = $this->firstPayment($subscription);

        PaymentSucceeded::dispatch($payment);
        $this->assertTrue($subscription->fresh()->current_period_ends_at->equalTo($periodEnd), 'the period comes from the provider, not our interval');

        PaymentFailed::dispatch($payment);
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::Active, $fresh->status, 'the provider runs the dunning');
        $this->assertSame(0, $fresh->recurring_attempts);
    }

    public function test_past_due_keeps_access_while_the_provider_retries_when_grace_access_allows_it(): void
    {
        Event::fake([SubscriptionPaymentFailed::class, SubscriptionAccessSuspended::class]);
        $subscription = $this->linked();

        $this->webhook(['event_id' => 'evt_1', 'provider_id' => 'sub_1', 'status' => 'past_due']);

        $fresh = $subscription->fresh();
        $this->assertNull($fresh->grace_ends_at, 'no grace window of ours — the provider decides when dunning ends');
        $this->assertTrue($fresh->isActive());
        $this->assertTrue(Subscription::query()->active()->whereKey($fresh->id)->exists());
        Event::assertDispatchedTimes(SubscriptionPaymentFailed::class, 1);
        Event::assertNotDispatched(SubscriptionAccessSuspended::class);
    }

    public function test_past_due_without_grace_access_cuts_access_and_says_so(): void
    {
        Event::fake([SubscriptionAccessSuspended::class]);
        $this->price->update(['grace_access' => false]);
        $subscription = $this->linked();

        $this->webhook(['event_id' => 'evt_1', 'provider_id' => 'sub_1', 'status' => 'past_due']);

        $this->assertFalse($subscription->fresh()->isActive());
        $this->assertFalse(Subscription::query()->active()->whereKey($subscription->id)->exists());
        Event::assertDispatchedTimes(SubscriptionAccessSuspended::class, 1);
    }

    public function test_cancellation_pause_and_resume_reported_by_the_provider_fire_their_events(): void
    {
        Event::fake([SubscriptionCancelled::class, SubscriptionPaused::class, SubscriptionResumed::class]);
        $subscription = $this->linked();

        $this->webhook(['event_id' => 'evt_1', 'provider_id' => 'sub_1', 'status' => 'paused']);
        $this->webhook(['event_id' => 'evt_2', 'provider_id' => 'sub_1', 'status' => 'active']);
        $this->webhook(['event_id' => 'evt_3', 'provider_id' => 'sub_1', 'status' => 'canceled', 'cancels_at' => now()->toIso8601String()]);

        Event::assertDispatchedTimes(SubscriptionPaused::class, 1);
        Event::assertDispatchedTimes(SubscriptionResumed::class, 1);
        Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);
        $this->assertFalse($subscription->fresh()->isActive());
    }

    public function test_cancel_at_period_end_is_forwarded_and_keeps_access_until_then(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        $subscription = $this->linked();

        $subscription->cancel();

        $fresh = $subscription->fresh();
        $this->assertTrue($fresh->cancels_at->equalTo($fresh->current_period_ends_at));
        $this->assertTrue($fresh->isActive());
        Event::assertNotDispatched(SubscriptionCancelled::class);
    }

    public function test_an_immediate_cancel_is_forwarded_and_announced_once_even_when_the_webhook_echoes_it(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        $subscription = $this->linked();

        $subscription->cancel(atPeriodEnd: false);
        $this->webhook(['event_id' => 'evt_1', 'provider_id' => 'sub_1', 'status' => 'canceled', 'cancels_at' => now()->toIso8601String()]);

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);
    }

    public function test_pause_resume_and_swap_are_forwarded(): void
    {
        $subscription = $this->linked();
        $until = now()->addWeek()->startOfSecond();

        $subscription->pause($until);
        $this->assertSame(SubscriptionStatus::Paused, $subscription->fresh()->status);
        $this->assertTrue($subscription->fresh()->pause_ends_at->equalTo($until));

        $subscription->fresh()->resume();
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);

        $newPrice = Price::create(['plan_id' => $this->price->plan_id, 'currency' => 'USD', 'amount' => 4900, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);
        $subscription->fresh()->swapPlan($newPrice);
        $this->assertSame($newPrice->id, $subscription->fresh()->price_id);
    }

    public function test_a_provider_managed_row_on_a_gateway_that_cannot_manage_it_refuses_instead_of_changing_locally(): void
    {
        $subscription = $this->subscription(['gateway' => 'monobank', 'external_id' => 'sub_1', 'status' => SubscriptionStatus::Active]);

        try {
            $subscription->cancel(atPeriodEnd: false);
            $this->fail('Cancelling only our row would leave the provider billing the customer.');
        } catch (NotSupportedException) {
        }

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
    }

    public function test_package_managed_subscriptions_keep_cancelling_locally(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        $subscription = $this->subscription(['gateway' => 'monobank', 'status' => SubscriptionStatus::Active]);

        $subscription->cancel(atPeriodEnd: false);

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);
    }

    public function test_gateways_listing_reports_the_subscription_capability(): void
    {
        $this->assertTrue(Billing::gateway('provider')['capabilities']['subscriptions']);
        $this->assertFalse(Billing::gateway('monobank')['capabilities']['subscriptions']);
    }

    private function webhook(array $event): void
    {
        $this->postJson(route('billing.webhook', ['gateway' => 'provider']), $event)->assertOk();
    }

    private function linked(array $attributes = []): Subscription
    {
        return $this->subscription([
            'external_id' => 'sub_1',
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addDays(10)->startOfSecond(),
            ...$attributes,
        ]);
    }

    private function subscription(array $attributes = []): Subscription
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Subscription::create([
            'status' => SubscriptionStatus::Incomplete,
            'gateway' => 'provider',
            'price_id' => $this->price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }

    private function firstPayment(Subscription $subscription): Payment
    {
        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'provider',
            'amount' => 2900,
            'currency' => 'USD',
            'payable_type' => Subscription::class,
            'payable_id' => $subscription->id,
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]);
    }
}
