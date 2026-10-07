<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionResumed;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class ExpirePausesTest extends TestCase
{
    public function test_paused_subscriptions_past_their_pause_ends_at_are_resumed(): void
    {
        Event::fake([SubscriptionResumed::class]);

        $due = $this->pausedSubscription(now()->subHour());
        $notYetDue = $this->pausedSubscription(now()->addWeek());
        $indefinite = $this->pausedSubscription(null);

        $this->artisan('billing:expire-pauses')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Active, $due->fresh()->status);
        $this->assertNull($due->fresh()->pause_ends_at);
        $this->assertSame(SubscriptionStatus::Paused, $notYetDue->fresh()->status);
        $this->assertSame(SubscriptionStatus::Paused, $indefinite->fresh()->status);

        Event::assertDispatchedTimes(SubscriptionResumed::class, 1);
        Event::assertDispatched(SubscriptionResumed::class, fn ($event) => $event->subscription->is($due));
    }

    public function test_a_period_that_ran_out_during_the_pause_restarts_on_resume(): void
    {
        $expired = $this->pausedSubscription(null);
        $expired->update(['current_period_ends_at' => now()->subMonths(3)]);
        $running = $this->pausedSubscription(null);
        $running->update(['current_period_ends_at' => $runningEnd = now()->addWeek()->startOfSecond()]);

        $expired->resume();
        $running->resume();

        $this->assertTrue($expired->fresh()->current_period_ends_at->isSameMinute(now()));
        $this->assertTrue($running->fresh()->current_period_ends_at->equalTo($runningEnd));
    }

    public function test_provider_managed_paused_subscriptions_are_skipped(): void
    {
        $subscription = $this->pausedSubscription(now()->subHour());
        $subscription->update(['external_id' => 'sub_provider_123']);

        $this->artisan('billing:expire-pauses')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Paused, $subscription->fresh()->status);
    }

    private function pausedSubscription(?\DateTimeInterface $pauseEndsAt): Subscription
    {
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 10000, 'pricing_type' => 'flat']);
        $user = TestUser::create(['name' => 'Buyer']);

        return Subscription::create([
            'status' => SubscriptionStatus::Paused,
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'pause_ends_at' => $pauseEndsAt,
        ]);
    }
}
