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

    public function test_pause_and_resume_toggle_status_and_fire_events(): void
    {
        Event::fake([SubscriptionPaused::class, SubscriptionResumed::class]);
        $subscription = $this->subscription(includedUnits: null);
        $subscription->update(['status' => SubscriptionStatus::Active]);

        $subscription->pause();
        $this->assertSame(SubscriptionStatus::Paused, $subscription->fresh()->status);

        $subscription->resume();
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);

        Event::assertDispatched(SubscriptionPaused::class);
        Event::assertDispatched(SubscriptionResumed::class);
    }

    private function subscription(?float $includedUnits): Subscription
    {
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create(['plan_id' => $plan->id, 'currency_code' => 'UAH', 'amount' => 10000, 'pricing_type' => 'flat', 'included_units' => $includedUnits]);
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
