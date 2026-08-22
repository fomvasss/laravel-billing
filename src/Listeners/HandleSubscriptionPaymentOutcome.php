<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Listeners;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentCanceled;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Models\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * Reacts to PaymentSucceeded/PaymentFailed/PaymentCanceled only when the Payment's payable is a
 * Subscription — i.e. a renewal charge (see ProcessRecurringChargesCommand), not an unrelated
 * one-off Order payment. Advances the period on success; on failure, applies the grace/dunning
 * rules from "Ідеї з support" in the package plan.
 */
class HandleSubscriptionPaymentOutcome
{
    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        $subscription = $event->payment->payable;

        if (! $subscription instanceof Subscription) {
            return;
        }

        // Refuses (and logs) on a canceled/ended/paused subscription — see recordRenewalSuccess().
        $subscription->recordRenewalSuccess($event->payment->gateway);
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        $this->recordFailure($event->payment->payable);
    }

    /**
     * A renewal charge that ended canceled/expired rather than declined — an off-session
     * PaymentIntent the gateway voided, a recurring order left to expire, or an attempt written
     * off by reconciliation. For the subscription it is the same outcome as a decline: the period
     * wasn't paid for. Without this it counted as nothing at all — the subscription kept its stale
     * current_period_ends_at and got re-charged on every run, never advancing dunning and never
     * hitting max_recurring_attempts.
     */
    public function handlePaymentCanceled(PaymentCanceled $event): void
    {
        $this->recordFailure($event->payment->payable);
    }

    protected function recordFailure(mixed $subscription): void
    {
        if (! $subscription instanceof Subscription) {
            return;
        }

        // A failed charge against a still-trialing subscription is a failed conversion attempt at
        // checkout, not a failed renewal — dunning here would cancel the trial after a few
        // declined cards. The trial keeps running; expire-trials ends it if nobody converts.
        if ($subscription->status === SubscriptionStatus::Trialing) {
            return;
        }

        // Dunning only applies while the subscription is still running. A late failure for a
        // canceled/ended one has nothing left to cancel, and on a paused one it would resurrect
        // the row as past_due — i.e. isActive() again, for a subscription the customer paused.
        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            Log::warning('Billing: ignored a renewal failure for a subscription that is no longer running', [
                'subscription_id' => $subscription->id,
                'status' => $subscription->status->value,
            ]);

            return;
        }

        // Grace/retries only for gateway-managed recurring charges — a manually-paid subscription
        // (gateway=null) has no saved method to retry against, so there's nothing to wait for.
        if ($subscription->gateway === null) {
            $subscription->update(['status' => SubscriptionStatus::Canceled, 'cancels_at' => now()]);
            SubscriptionCancelled::dispatch($subscription);

            return;
        }

        $subscription->recordRenewalFailure();
    }
}
