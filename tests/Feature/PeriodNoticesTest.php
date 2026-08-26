<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionPeriodEnding;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class PeriodNoticesTest extends TestCase
{
    public function test_nothing_fires_while_the_notice_list_is_empty(): void
    {
        Event::fake([SubscriptionPeriodEnding::class]);
        $subscription = $this->activeSubscription(now()->addDay());

        $this->artisan('billing:send-period-notices')->assertSuccessful();

        Event::assertNotDispatched(SubscriptionPeriodEnding::class);
        $this->assertNull($subscription->fresh()->period_notices_sent);
    }

    public function test_a_notice_fires_once_within_the_window(): void
    {
        Event::fake([SubscriptionPeriodEnding::class]);
        config()->set('billing.period_ending_notices', ['3 days']);

        $endingSoon = $this->activeSubscription(now()->addDays(2));
        $farAway = $this->activeSubscription(now()->addDays(10));

        $this->artisan('billing:send-period-notices')->assertSuccessful();
        $this->artisan('billing:send-period-notices')->assertSuccessful(); // second run — no re-notify

        Event::assertDispatchedTimes(SubscriptionPeriodEnding::class, 1);
        Event::assertDispatched(
            SubscriptionPeriodEnding::class,
            fn ($event) => $event->subscription->is($endingSoon) && $event->notice === '3 days' && $event->willRenew,
        );
        $this->assertSame(['3 days'], $endingSoon->fresh()->period_notices_sent);
        $this->assertNull($farAway->fresh()->period_notices_sent);
    }

    public function test_several_due_notices_fire_as_one_not_a_burst(): void
    {
        Event::fake([SubscriptionPeriodEnding::class]);
        config()->set('billing.period_ending_notices', ['7 days', '3 days', '1 day']);

        $subscription = $this->activeSubscription(now()->addDays(2));

        $this->artisan('billing:send-period-notices')->assertSuccessful();

        Event::assertDispatchedTimes(SubscriptionPeriodEnding::class, 1);
        Event::assertDispatched(SubscriptionPeriodEnding::class, fn ($event) => $event->notice === '3 days');
        $this->assertEqualsCanonicalizing(['7 days', '3 days'], $subscription->fresh()->period_notices_sent);

        // A day later the 1-day reminder becomes due — second, distinct notice.
        $this->travel(26)->hours();
        $this->artisan('billing:send-period-notices')->assertSuccessful();

        Event::assertDispatchedTimes(SubscriptionPeriodEnding::class, 2);
        Event::assertDispatched(SubscriptionPeriodEnding::class, fn ($event) => $event->notice === '1 day');
    }

    public function test_a_cancelled_subscription_is_told_the_period_is_its_last(): void
    {
        Event::fake([SubscriptionPeriodEnding::class]);
        config()->set('billing.period_ending_notices', ['3 days']);

        $subscription = $this->activeSubscription(now()->addDays(2));
        $subscription->update(['cancels_at' => $subscription->current_period_ends_at]);

        $this->artisan('billing:send-period-notices')->assertSuccessful();

        Event::assertDispatched(SubscriptionPeriodEnding::class, fn ($event) => $event->willRenew === false);
    }

    public function test_a_price_can_turn_its_own_notices_off_and_on(): void
    {
        Event::fake([SubscriptionPeriodEnding::class]);
        config()->set('billing.period_ending_notices', ['3 days']);

        $silent = $this->activeSubscription(now()->addDays(2));
        $silent->price->update(['period_ending_notices' => []]);

        $ownPace = $this->activeSubscription(now()->addDays(9));
        $ownPace->price->update(['period_ending_notices' => ['10 days']]);

        $this->artisan('billing:send-period-notices')->assertSuccessful();

        Event::assertDispatchedTimes(SubscriptionPeriodEnding::class, 1);
        Event::assertDispatched(SubscriptionPeriodEnding::class, fn ($event) => $event->subscription->is($ownPace));
    }

    public function test_provider_managed_and_non_active_subscriptions_are_skipped(): void
    {
        Event::fake([SubscriptionPeriodEnding::class]);
        config()->set('billing.period_ending_notices', ['3 days']);

        $this->activeSubscription(now()->addDay())->update(['external_id' => 'sub_ext_1']);
        $this->activeSubscription(now()->addDay())->update(['status' => SubscriptionStatus::PastDue]);
        $this->activeSubscription(now()->addDay())->update(['status' => SubscriptionStatus::Paused]);

        $this->artisan('billing:send-period-notices')->assertSuccessful();

        Event::assertNotDispatched(SubscriptionPeriodEnding::class);
    }

    public function test_a_renewal_clears_the_markers_so_the_next_period_notifies_again(): void
    {
        config()->set('billing.period_ending_notices', ['3 days']);
        $subscription = $this->activeSubscription(now()->addDays(2));

        $this->artisan('billing:send-period-notices')->assertSuccessful();
        $this->assertSame(['3 days'], $subscription->fresh()->period_notices_sent);

        PaymentSucceeded::dispatch(Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => Subscription::class,
            'payable_id' => $subscription->id,
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]));

        $this->assertNull($subscription->fresh()->period_notices_sent);
    }

    private function activeSubscription(\DateTimeInterface $periodEndsAt): Subscription
    {
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'currency' => 'UAH',
            'amount' => 10000,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);
        $user = TestUser::create(['name' => 'Buyer']);

        return Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => 'fake',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => $periodEndsAt,
        ]);
    }
}
