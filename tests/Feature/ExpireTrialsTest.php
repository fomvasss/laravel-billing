<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

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
