<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

/**
 * HandleSubscriptionPaymentOutcome only reacts when Payment::payable is a Subscription — these
 * simulate the outcome step of the renewal cycle directly (see ProcessRecurringChargesCommand for
 * how the Payment/charge attempt itself gets created).
 */
class SubscriptionRenewalTest extends TestCase
{
    public function test_a_successful_renewal_advances_the_period_and_resets_attempts(): void
    {
        $subscription = $this->activeMonthlySubscription();
        $subscription->update(['recurring_attempts' => 2, 'current_period_ends_at' => now()->subDay()]);
        $expectedNextPeriod = $subscription->current_period_ends_at->copy()->addMonth();

        PaymentSucceeded::dispatch($this->renewalPayment($subscription));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(0, $subscription->recurring_attempts);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertTrue($subscription->current_period_ends_at->equalTo($expectedNextPeriod));
    }

    public function test_a_failed_renewal_moves_to_past_due_with_a_grace_period(): void
    {
        $subscription = $this->activeMonthlySubscription();

        PaymentFailed::dispatch($this->renewalPayment($subscription));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->recurring_attempts);
        $this->assertNotNull($subscription->grace_ends_at);
    }

    public function test_repeated_failures_cancel_the_subscription_once_max_attempts_is_reached(): void
    {
        config(['billing.max_recurring_attempts' => 3]);
        $subscription = $this->activeMonthlySubscription();

        PaymentFailed::dispatch($this->renewalPayment($subscription));
        PaymentFailed::dispatch($this->renewalPayment($subscription));
        PaymentFailed::dispatch($this->renewalPayment($subscription));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertSame(3, $subscription->recurring_attempts);
    }

    public function test_a_manually_paid_subscription_cancels_immediately_on_failure_without_grace(): void
    {
        $subscription = $this->activeMonthlySubscription();
        $subscription->update(['gateway' => null]);

        PaymentFailed::dispatch($this->renewalPayment($subscription));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
    }

    private function activeMonthlySubscription(): Subscription
    {
        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = Price::create(['plan_id' => $plan->id, 'currency_code' => 'UAH', 'amount' => 10000, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);
        $user = TestUser::create(['name' => 'Buyer']);

        return Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => 'fake',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now(),
        ]);
    }

    private function renewalPayment(Subscription $subscription): Payment
    {
        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => $subscription->gateway,
            'amount' => 10000,
            'currency_code' => 'UAH',
            'payable_type' => Subscription::class,
            'payable_id' => $subscription->id,
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]);
    }
}
