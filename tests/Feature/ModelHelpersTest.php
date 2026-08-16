<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

class ModelHelpersTest extends TestCase
{
    public function test_subscription_is_active_while_trialing_active_or_within_grace(): void
    {
        $this->assertTrue($this->subscription(['status' => SubscriptionStatus::Trialing])->isActive());
        $this->assertTrue($this->subscription(['status' => SubscriptionStatus::Active])->isActive());
        $this->assertTrue($this->subscription(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->addDay()])->isActive());

        $this->assertFalse($this->subscription(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->subDay()])->isActive());
        $this->assertFalse($this->subscription(['status' => SubscriptionStatus::Canceled])->isActive());
        $this->assertFalse($this->subscription(['status' => SubscriptionStatus::Ended])->isActive());
        $this->assertFalse($this->subscription(['status' => SubscriptionStatus::Paused])->isActive());
    }

    public function test_subscription_on_trial_requires_a_future_trial_end(): void
    {
        $this->assertTrue($this->subscription(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addWeek()])->onTrial());
        $this->assertFalse($this->subscription(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->subDay()])->onTrial());
        $this->assertFalse($this->subscription(['status' => SubscriptionStatus::Active, 'trial_ends_at' => now()->addWeek()])->onTrial());
    }

    public function test_subscription_is_cancelling_until_the_period_ends(): void
    {
        $subscription = $this->subscription(['status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addWeek()]);
        $subscription->cancel(); // at period end

        $this->assertTrue($subscription->fresh()->isCancelling());
        $this->assertFalse($subscription->fresh()->isCanceled());

        $subscription->cancel(atPeriodEnd: false);

        $this->assertTrue($subscription->fresh()->isCanceled());
        $this->assertFalse($subscription->fresh()->isCancelling());
    }

    public function test_subscription_scopes(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $this->subscription(['status' => SubscriptionStatus::Active], $user);
        $this->subscription(['status' => SubscriptionStatus::Canceled], $user);
        // active() matches isActive(): past_due inside the grace window still counts, expired doesn't
        $this->subscription(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->addDay()], $user);
        $this->subscription(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->subDay()], $user);

        $this->assertCount(2, Subscription::active()->get());
        $this->assertCount(4, Subscription::forBillable($user)->get());
    }

    public function test_payment_status_helpers_and_scopes(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $paid = $this->payment(['status' => 'paid'], $user);
        $pending = $this->payment(['status' => 'pending'], $user);

        $this->assertTrue($paid->isPaid());
        $this->assertFalse($paid->isPending());
        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isFailed());
        $this->assertFalse($paid->isRefund());

        $this->assertCount(1, Payment::paid()->get());
        $this->assertCount(1, Payment::pending()->get());
        $this->assertCount(2, Payment::forBillable($user)->get());
    }

    public function test_payment_refunded_amount_sums_only_paid_refunds(): void
    {
        $charge = $this->payment(['status' => 'paid', 'amount' => 10000]);

        $this->payment(['status' => 'paid', 'type' => 'refund', 'amount' => 2500, 'parent_payment_id' => $charge->id]);
        $this->payment(['status' => 'failed', 'type' => 'refund', 'amount' => 9999, 'parent_payment_id' => $charge->id]);

        $this->assertSame(2500, $charge->refundedAmount());
    }

    public function test_payment_has_active_payment_url(): void
    {
        $this->assertFalse($this->payment([])->hasActivePaymentUrl());
        $this->assertTrue($this->payment(['payment_url' => 'https://x.test', 'payment_url_expires_at' => null])->hasActivePaymentUrl());
        $this->assertTrue($this->payment(['payment_url' => 'https://x.test', 'payment_url_expires_at' => now()->addHour()])->hasActivePaymentUrl());
        $this->assertFalse($this->payment(['payment_url' => 'https://x.test', 'payment_url_expires_at' => now()->subHour()])->hasActivePaymentUrl());
    }

    private function subscription(array $attributes, ?TestUser $user = null): Subscription
    {
        $user ??= TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 10000, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);

        return Subscription::create([
            'gateway' => 'fake',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }

    private function payment(array $attributes, ?TestUser $user = null): Payment
    {
        $user ??= TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
