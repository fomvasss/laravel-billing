<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\TrialWillEnd;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class ExpireTrialsTest extends TestCase
{
    public function test_expired_trials_are_marked_ended(): void
    {
        $expired = $this->trialSubscription(now()->subDay());
        $stillRunning = $this->trialSubscription(now()->addWeek());

        $this->artisan('billing:expire-trials')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Ended, $expired->fresh()->status);
        $this->assertSame(SubscriptionStatus::Trialing, $stillRunning->fresh()->status);
    }

    public function test_trial_will_end_fires_once_within_the_notice_window(): void
    {
        Event::fake([TrialWillEnd::class]);

        $endingSoon = $this->trialSubscription(now()->addDays(2)); // inside the default 3-day window
        $farAway = $this->trialSubscription(now()->addDays(10));

        $this->artisan('billing:expire-trials')->assertSuccessful();
        $this->artisan('billing:expire-trials')->assertSuccessful(); // second run — no re-notify

        Event::assertDispatchedTimes(TrialWillEnd::class, 1);
        Event::assertDispatched(TrialWillEnd::class, fn ($event) => $event->subscription->is($endingSoon));
        $this->assertNotNull($endingSoon->fresh()->trial_ends_notified_at);
        $this->assertNull($farAway->fresh()->trial_ends_notified_at);
    }

    private function trialSubscription(\DateTimeInterface $trialEndsAt): Subscription
    {
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create(['plan_id' => $plan->id, 'currency_code' => 'UAH', 'amount' => 0, 'pricing_type' => 'flat']);
        $user = TestUser::create(['name' => 'Buyer']);

        return Subscription::create([
            'status' => SubscriptionStatus::Trialing,
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'trial_ends_at' => $trialEndsAt,
        ]);
    }
}
