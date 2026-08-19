<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionPaused;
use Fomvasss\Billing\Events\SubscriptionResumed;
use Fomvasss\Billing\Events\UsageLimitReached;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class SubscriptionUsageTest extends TestCase
{
    public function test_remaining_usage_is_null_without_a_quota(): void
    {
        $subscription = $this->subscription(includedUnits: null);

        $this->assertNull($subscription->remainingUsage());
    }

    public function test_report_usage_tracks_consumption_against_the_quota(): void
    {
        $subscription = $this->subscription(includedUnits: 100.0);

        $subscription->reportUsage(30);

        $this->assertSame(30.0, $subscription->fresh()->current_usage);
        $this->assertSame(70.0, $subscription->fresh()->remainingUsage());
    }

    public function test_report_usage_ignores_a_repeated_idempotency_key(): void
    {
        $subscription = $this->subscription(includedUnits: 100.0);

        $subscription->reportUsage(30, idempotencyKey: 'run-1');
        $subscription->reportUsage(30, idempotencyKey: 'run-1');

        $this->assertSame(30.0, $subscription->fresh()->current_usage);
    }

    public function test_usage_limit_reached_fires_once_when_crossing_the_quota(): void
    {
        Event::fake([UsageLimitReached::class]);
        $subscription = $this->subscription(includedUnits: 100.0);

        $subscription->reportUsage(90);
        $subscription->reportUsage(20); // crosses 100
        $subscription->reportUsage(5); // already over — should not fire again

        Event::assertDispatchedTimes(UsageLimitReached::class, 1);
    }

    public function test_a_successful_renewal_resets_usage_on_a_flat_price_with_a_quota(): void
    {
        $subscription = $this->subscription(includedUnits: 100.0);
        $subscription->reportUsage(80);

        $this->renew($subscription);

        // fresh paid period = fresh allowance, even though the price is flat (quota is orthogonal
        // to pricing_type — the "4000 tokens included per month at a fixed price" case)
        $this->assertSame(0.0, $subscription->fresh()->current_usage);
    }

    public function test_the_first_payment_stamps_its_gateway_onto_a_gateway_less_subscription(): void
    {
        $subscription = $this->subscription(includedUnits: null);
        $subscription->update(['gateway' => null]); // trial created before the payment method is known

        $this->renew($subscription); // the renew() helper pays via the fake gateway

        $this->assertSame('fake', $subscription->fresh()->gateway);
    }

    public function test_a_renewal_keeps_usage_on_a_quota_less_flat_price(): void
    {
        $subscription = $this->subscription(includedUnits: null);
        $subscription->reportUsage(80);

        $this->renew($subscription);

        $this->assertSame(80.0, $subscription->fresh()->current_usage);
    }

    private function renew(Subscription $subscription): void
    {
        $payment = \Fomvasss\Billing\Models\Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => $subscription->getMorphClass(),
            'payable_id' => $subscription->id,
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]);

        $payment->update(['status' => 'paid']);
        \Fomvasss\Billing\Events\PaymentSucceeded::dispatch($payment);
    }

    public function test_pause_and_resume_toggle_status_and_fire_events(): void
    {
        Event::fake([SubscriptionPaused::class, SubscriptionResumed::class]);
        $subscription = $this->subscription(includedUnits: null);
        $subscription->update(['status' => SubscriptionStatus::Active]);

        $subscription->pause();
        $this->assertSame(SubscriptionStatus::Paused, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->pause_ends_at);

        $subscription->resume();
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);

        Event::assertDispatched(SubscriptionPaused::class);
        Event::assertDispatched(SubscriptionResumed::class);
    }

    public function test_pause_with_until_stores_it_and_resume_clears_it(): void
    {
        $subscription = $this->subscription(includedUnits: null);
        $subscription->update(['status' => SubscriptionStatus::Active]);
        $until = now()->addWeek();

        $subscription->pause($until);
        // Compared to the second — the DB column truncates microseconds, $until doesn't.
        $this->assertSame($until->format('Y-m-d H:i:s'), $subscription->fresh()->pause_ends_at->format('Y-m-d H:i:s'));

        $subscription->resume();
        $this->assertNull($subscription->fresh()->pause_ends_at);
    }

    private function subscription(?float $includedUnits): Subscription
    {
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 10000, 'pricing_type' => 'flat', 'included_units' => $includedUnits]);
        $user = TestUser::create(['name' => 'Buyer']);

        return Subscription::create([
            'status' => SubscriptionStatus::Active,
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_usage' => 0,
        ]);
    }
}
