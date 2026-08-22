<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

/** Price::trial_days is the trial length; a subscription created as trialing picks it up. */
class TrialDaysTest extends TestCase
{
    public function test_a_trialing_subscription_takes_its_trial_length_from_the_price(): void
    {
        $subscription = $this->subscribe(['trial_days' => 14], ['status' => SubscriptionStatus::Trialing]);

        $this->assertTrue($subscription->trial_ends_at->isSameDay(now()->addDays(14)));
        $this->assertTrue($subscription->onTrial());
    }

    public function test_an_explicit_trial_end_date_wins(): void
    {
        $ends = now()->addDays(3);

        $subscription = $this->subscribe(['trial_days' => 14], [
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => $ends,
        ]);

        $this->assertTrue($subscription->trial_ends_at->isSameDay($ends));
    }

    public function test_a_price_without_trial_days_starts_no_trial(): void
    {
        $subscription = $this->subscribe(['trial_days' => 0], ['status' => SubscriptionStatus::Trialing]);

        $this->assertNull($subscription->trial_ends_at);
    }

    /** A trial_ends_at on an active row is dead data — onTrial() and expire-trials key off the status. */
    public function test_an_active_subscription_gets_no_trial_date(): void
    {
        $subscription = $this->subscribe(['trial_days' => 14], ['status' => SubscriptionStatus::Active]);

        $this->assertNull($subscription->trial_ends_at);
    }

    private function subscribe(array $priceAttributes, array $attributes): Subscription
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => 'stripe',
            'currency' => 'USD',
            'amount' => 2900,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
            ...$priceAttributes,
        ]);

        return Subscription::create([
            'gateway' => 'stripe',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
