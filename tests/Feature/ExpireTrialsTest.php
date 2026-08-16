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
        Event::assertDispatched(TrialWillEnd::class, fn ($event) => $event->subscription->is($endingSoon) && $event->notice === '3 days');
        $this->assertSame(['3 days'], $endingSoon->fresh()->trial_notices_sent);
        $this->assertNull($farAway->fresh()->trial_notices_sent);
    }

    public function test_multiple_notices_fire_in_sequence_and_never_burst(): void
    {
        Event::fake([TrialWillEnd::class]);
        config()->set('billing.trial_ending_notices', ['7 days', '3 days', '1 day']);

        // Created with 2 days left — both the 7-day and 3-day windows already apply, but only the
        // closest one should actually fire; the wider one is silently marked as sent.
        $subscription = $this->trialSubscription(now()->addDays(2));

        $this->artisan('billing:expire-trials')->assertSuccessful();
        Event::assertDispatchedTimes(TrialWillEnd::class, 1);
        Event::assertDispatched(TrialWillEnd::class, fn ($event) => $event->notice === '3 days');
        $this->assertEqualsCanonicalizing(['7 days', '3 days'], $subscription->fresh()->trial_notices_sent);

        // A day later the 1-day reminder becomes due — second, distinct notice.
        $this->travel(26)->hours();
        $this->artisan('billing:expire-trials')->assertSuccessful();

        Event::assertDispatchedTimes(TrialWillEnd::class, 2);
        Event::assertDispatched(TrialWillEnd::class, fn ($event) => $event->notice === '1 day');
    }

    public function test_a_price_can_override_or_disable_the_global_notice_list(): void
    {
        Event::fake([TrialWillEnd::class]);
        config()->set('billing.trial_ending_notices', ['3 days']);

        $global = $this->trialSubscription(now()->addDays(2)); // uses the global '3 days'
        $custom = $this->trialSubscription(now()->addDays(2));
        $custom->price->update(['trial_ending_notices' => ['7 days']]); // its own cadence
        $silent = $this->trialSubscription(now()->addDays(2));
        $silent->price->update(['trial_ending_notices' => []]); // [] = no reminders at all

        $this->artisan('billing:expire-trials')->assertSuccessful();

        Event::assertDispatchedTimes(TrialWillEnd::class, 2);
        Event::assertDispatched(TrialWillEnd::class, fn ($event) => $event->subscription->is($global) && $event->notice === '3 days');
        Event::assertDispatched(TrialWillEnd::class, fn ($event) => $event->subscription->is($custom) && $event->notice === '7 days');
        $this->assertNull($silent->fresh()->trial_notices_sent);
    }

    public function test_short_cycle_notices_work_in_minutes(): void
    {
        Event::fake([TrialWillEnd::class]);
        config()->set('billing.trial_ending_notices', ['1 hour', '15 minutes']);

        $subscription = $this->trialSubscription(now()->addMinutes(30)); // самокат/паркомісце

        $this->artisan('billing:expire-trials')->assertSuccessful();

        Event::assertDispatched(TrialWillEnd::class, fn ($event) => $event->notice === '1 hour');
        $this->assertSame(['1 hour'], $subscription->fresh()->trial_notices_sent);
    }

    private function trialSubscription(\DateTimeInterface $trialEndsAt): Subscription
    {
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 0, 'pricing_type' => 'flat']);
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
