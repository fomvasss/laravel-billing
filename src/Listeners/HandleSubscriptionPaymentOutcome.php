<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Listeners;

use Fomvasss\Billing\Enums\Interval;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Events\SubscriptionPaymentFailed;
use Fomvasss\Billing\Events\SubscriptionRenewed;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Reacts to PaymentSucceeded/PaymentFailed only when the Payment's payable is a Subscription —
 * i.e. a renewal charge (see ProcessRecurringChargesCommand), not an unrelated one-off Order
 * payment. Advances the period on success; on failure, applies the grace/dunning rules from
 * "Ідеї з support" in the package plan.
 */
class HandleSubscriptionPaymentOutcome
{
    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        $subscription = $event->payment->payable;

        if (! $subscription instanceof Subscription) {
            return;
        }

        $price = $subscription->price;

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => $this->nextPeriodEnd($subscription, $price),
            'recurring_attempts' => 0,
            'grace_ends_at' => null,
            // metered usage resets on a successful renewal — see "Ліміти/квоти" in the plan
            'current_usage' => $price?->pricing_type?->value === 'metered' ? 0 : $subscription->current_usage,
        ]);

        SubscriptionRenewed::dispatch($subscription);
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        $subscription = $event->payment->payable;

        if (! $subscription instanceof Subscription) {
            return;
        }

        // Grace/retries only for gateway-managed recurring charges — a manually-paid subscription
        // (gateway=null) has no saved method to retry against, so there's nothing to wait for.
        if ($subscription->gateway === null) {
            $subscription->update(['status' => SubscriptionStatus::Canceled, 'cancels_at' => now()]);
            SubscriptionCancelled::dispatch($subscription);

            return;
        }

        $attempts = $subscription->recurring_attempts + 1;
        $maxAttempts = (int) config('billing.max_recurring_attempts', 3);

        if ($attempts >= $maxAttempts) {
            $subscription->update(['status' => SubscriptionStatus::Canceled, 'recurring_attempts' => $attempts, 'cancels_at' => now()]);
            SubscriptionCancelled::dispatch($subscription);

            return;
        }

        $subscription->update([
            'status' => SubscriptionStatus::PastDue,
            'recurring_attempts' => $attempts,
            'grace_ends_at' => now()->addDays((int) config('billing.grace_period_days', 3)),
        ]);

        SubscriptionPaymentFailed::dispatch($subscription);
    }

    protected function nextPeriodEnd(Subscription $subscription, ?Price $price): ?Carbon
    {
        if ($price?->interval === null) {
            return null; // one-off/lifetime price — no recurring cycle to advance
        }

        $base = $subscription->current_period_ends_at ?? now();
        $count = $price->interval_count;

        return match ($price->interval) {
            Interval::Minute => $base->copy()->addMinutes($count),
            Interval::Hour => $base->copy()->addHours($count),
            Interval::Day => $base->copy()->addDays($count),
            Interval::Week => $base->copy()->addWeeks($count),
            Interval::Month => $base->copy()->addMonths($count),
            Interval::Year => $base->copy()->addYears($count),
        };
    }
}
