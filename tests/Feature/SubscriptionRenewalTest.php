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

    public function test_month_advance_clamps_instead_of_overflowing_into_the_next_month(): void
    {
        $subscription = $this->activeMonthlySubscription();
        // Jan 30 + 1 month: Carbon's default addMonth() would overflow "Feb 30" into Mar 2 —
        // the customer would skip February's charge entirely.
        $subscription->update(['current_period_ends_at' => \Illuminate\Support\Carbon::parse('2026-01-30 15:24:00')]);

        PaymentSucceeded::dispatch($this->renewalPayment($subscription));

        $this->assertSame('2026-02-28 15:24:00', $subscription->fresh()->current_period_ends_at->format('Y-m-d H:i:s'));
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

    public function test_each_failure_waits_longer_than_the_last(): void
    {
        $subscription = $this->activeMonthlySubscription();

        // Default ladder: 6h after the first failure, 24h after the second, 48h after the third.
        foreach ([6, 24, 48] as $expectedHours) {
            PaymentFailed::dispatch($this->renewalPayment($subscription));

            $subscription->refresh();

            $this->assertSame(
                now()->addHours($expectedHours)->format('Y-m-d H:i'),
                $subscription->next_retry_at->format('Y-m-d H:i'),
                "after failure #{$subscription->recurring_attempts}"
            );
        }

        // Fourth failure is max_recurring_attempts — no fifth wait, the subscription is done.
        PaymentFailed::dispatch($this->renewalPayment($subscription));

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
    }

    /** A list shorter than max_recurring_attempts keeps using its last entry rather than running out. */
    public function test_a_short_interval_list_repeats_its_last_entry(): void
    {
        config(['billing.retry_intervals' => ['1 hour'], 'billing.max_recurring_attempts' => 4]);
        $subscription = $this->activeMonthlySubscription();

        foreach ([1, 2, 3] as $failure) {
            PaymentFailed::dispatch($this->renewalPayment($subscription));

            $this->assertSame(
                now()->addHour()->format('Y-m-d H:i'),
                $subscription->fresh()->next_retry_at->format('Y-m-d H:i'),
                "after failure #{$failure}"
            );
        }
    }

    /** null on the Price = the global list; its own array = its own pace, like trial_ending_notices. */
    public function test_a_price_can_set_its_own_retry_pace(): void
    {
        $subscription = $this->activeMonthlySubscription();
        $subscription->price->update(['retry_intervals' => ['15 minutes', '2 hours']]);

        PaymentFailed::dispatch($this->renewalPayment($subscription));
        $this->assertSame(now()->addMinutes(15)->format('Y-m-d H:i'), $subscription->fresh()->next_retry_at->format('Y-m-d H:i'));

        PaymentFailed::dispatch($this->renewalPayment($subscription->fresh()));
        $this->assertSame(now()->addHours(2)->format('Y-m-d H:i'), $subscription->fresh()->next_retry_at->format('Y-m-d H:i'));
    }

    /** [] on the Price = don't retry this one at all, the mirror of "[] = no trial notices". */
    public function test_an_empty_interval_list_cancels_on_the_first_failure(): void
    {
        $subscription = $this->activeMonthlySubscription();
        $subscription->price->update(['retry_intervals' => []]);

        PaymentFailed::dispatch($this->renewalPayment($subscription));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertSame(1, $subscription->recurring_attempts);
    }

    /**
     * The window is anchored on the next attempt, not on now() — otherwise a retry pace longer than
     * grace_period_days would strand a past_due subscription without access between two attempts
     * and hand it back on the next failure.
     */
    public function test_the_grace_window_always_outlives_the_wait_for_the_next_retry(): void
    {
        config(['billing.retry_intervals' => ['10 days'], 'billing.grace_period_days' => 3]);
        $subscription = $this->activeMonthlySubscription();

        PaymentFailed::dispatch($this->renewalPayment($subscription));

        $subscription->refresh();

        $this->assertSame(
            $subscription->next_retry_at->copy()->addDays(3)->format('Y-m-d H:i'),
            $subscription->grace_ends_at->format('Y-m-d H:i'),
        );
        $this->assertTrue($subscription->isActive()); // grace_access is on by default — access holds
        $this->travelTo($subscription->next_retry_at->copy()->subMinute());
        $this->assertTrue($subscription->fresh()->isActive()); // still on, right up to the retry
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
        $price = Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 10000, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);
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
            'currency' => 'UAH',
            'payable_type' => Subscription::class,
            'payable_id' => $subscription->id,
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]);
    }
}
