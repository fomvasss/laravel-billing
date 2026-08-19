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
