<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Contracts\TokenizesPaymentMethod;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\PaymentType;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Only INITIATES the charge (creates a pending Payment, calls chargePaymentMethod()) — the outcome
 * arrives later via the normal webhook pipeline (PaymentSucceeded/PaymentFailed), handled by
 * HandleSubscriptionPaymentOutcome, not here. See "Підписки, конвертація валют, scheduler" in the
 * package plan.
 */
class ProcessRecurringChargesCommand extends Command
{
    protected $signature = 'billing:process-recurring-charges';

    protected $description = 'Charge subscriptions whose current period has ended, using a saved payment method';

    public function handle(BillingManager $billing): int
    {
        $this->finalizeDueCancellations();

        $count = 0;

        $this->dueForRenewal(Subscription::query())
            ->chunkById(200, function ($subscriptions) use ($billing, &$count) {
                foreach ($subscriptions as $subscription) {
                    try {
                        if ($this->charge($subscription, $billing)) {
                            $count++;
                        }
                    } catch (\Throwable $exception) {
                        // One bad gateway/subscription must not strand the rest of the batch.
                        report($exception);
                        $this->error("Subscription {$subscription->id}: {$exception->getMessage()}");
                    }
                }
            });

        $this->info("Attempted {$count} recurring charge(s).");

        return self::SUCCESS;
    }

    /**
     * cancel(atPeriodEnd: true) only stamps cancels_at — this is where the stamp takes effect.
     * Without this pass a period-end-cancelled subscription would sail straight into the charge
     * query below and be billed for another period. Runs for gateway-less (manual) subscriptions
     * too — their cancels_at has no other consumer.
     */
    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    protected function dueForRenewal(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->whereNotNull('gateway')
            // Provider-managed subscriptions (Subscription::isProviderManaged()) renew on the
            // gateway's side — charging here would race the provider's own renewal (a late
            // `renewed` webhook must not trigger a second debit from us).
            ->whereNull('external_id')
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', now())
            // Dunning pacing: a failed attempt stamps next_retry_at (retry_interval_hours ahead) —
            // until then the hourly run leaves the subscription alone.
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()));
    }

    protected function finalizeDueCancellations(): void
    {
        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            // A provider-managed subscription's cancellation is finalized by the gateway's own
            // `canceled` webhook — finalizing it here too would double-dispatch SubscriptionCancelled.
            ->whereNull('external_id')
            ->whereNotNull('cancels_at')
            ->where('cancels_at', '<=', now())
            ->chunkById(200, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $subscription->update(['status' => SubscriptionStatus::Canceled]);

                    SubscriptionCancelled::dispatch($subscription);
                }
            });
    }

    protected function charge(Subscription $subscription, BillingManager $billing): bool
    {
        $driver = $billing->driver($subscription->gateway, $subscription->billable?->tenantId());

        if (! $driver instanceof TokenizesPaymentMethod) {
            return false; // gateway can't do off-session charges — nothing this command can do
        }

        // Deciding whether this period gets charged is a read (is a renewal already pending?)
        // followed by a write (the Payment row) — two of these interleaving debit the card twice.
        // The row lock serializes them: withoutOverlapping() only covers the scheduler's own runs,
        // and only when the app's cache store is shared, so it can't be the thing standing between
        // a manual `artisan billing:process-recurring-charges` and the scheduled one. The gateway
        // call itself stays outside — a transaction must never span an HTTP round trip.
        $claim = DB::transaction(function () use ($subscription, $billing) {
            // Re-read under the lock and re-apply the same conditions the batch query used: by now
            // a concurrent run may have advanced the period, stamped next_retry_at or cancelled it.
            $subscription = $this->dueForRenewal(Subscription::query())
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->first();

            return $subscription === null ? null : $this->claimRenewal($subscription, $billing);
        });

        if ($claim === null) {
            return false;
        }

        [$payment, $method] = $claim;

        try {
            $billing->chargeWithMethod($payment, $method);
        } catch (\Throwable $exception) {
            // The attempt never got off the ground (network timeout, rejected request, a gateway
            // 5xx). Leaving the row pending would be the worst outcome: the pending-renewal guard
            // above would block this subscription's renewals forever, while reconciliation can't
            // resolve a row that never got an external_id. Writing it off as failed feeds the
            // normal dunning path instead — and if the charge did reach the bank after all, its
            // webhook still lands well within retry_interval_hours and flips the row to paid.
            $payment->transitionTo(PaymentStatus::Failed, ['raw_response' => ['exception' => $exception->getMessage()]]);

            PaymentFailed::dispatch($payment);

            throw $exception;
        }

        return true;
    }

    /**
     * Runs under the subscription's row lock. Returns the pending Payment and the card to charge
     * it with, or null when this period needs no gateway call at all (already claimed, no card,
     * nothing owed).
     *
     * @return array{Payment, PaymentMethod}|null
     */
    protected function claimRenewal(Subscription $subscription, BillingManager $billing): ?array
    {
        // A pending renewal from a previous run whose webhook hasn't landed yet — initiating
        // another charge now would debit the card twice for the same period. Reconciliation
        // resolves the pending row (paid/failed/canceled) first, and only then may a new attempt
        // happen.
        $hasPendingRenewal = Payment::query()
            ->where('payable_type', $subscription->getMorphClass())
            ->where('payable_id', $subscription->id)
            ->where('status', PaymentStatus::Pending)
            ->exists();

        if ($hasPendingRenewal) {
            return null;
        }

        $price = $subscription->price;
        $resolved = $billing->resolveChargeAmount($price, $subscription->gateway);
        $multiplier = $price->chargeMultiplier($subscription);

        $amount = new Money((int) round($resolved->money->amount * $multiplier), $resolved->money->currency);

        // Nothing to charge — a metered period nobody used, or a licensed one down to zero seats.
        // Every gateway rejects a zero/negative debit, and the rejected attempt would leave a
        // pending Payment behind that blocks this subscription's renewals for good. The period is
        // still owed to the customer, so advance it directly.
        //
        // Checked BEFORE the card: whether a card is on file is irrelevant to a period that owes
        // nothing, and dunning a customer over a bill of zero would eventually cancel them for it.
        if ($amount->amount <= 0) {
            $subscription->recordRenewalSuccess();

            return null;
        }

        $method = PaymentMethod::query()
            ->where('billable_type', $subscription->billable_type)
            ->where('billable_id', $subscription->billable_id)
            ->where('gateway', $subscription->gateway)
            ->where('is_default', true)
            ->first();

        // An expired card is a decline the bank hasn't been asked about yet — same dunning
        // treatment, without the round trip (and without the gateway logging a failure).
        if ($method !== null && $method->expires_at !== null && $method->expires_at->isPast()) {
            $method = null;
        }

        if ($method === null) {
            // No saved card to charge (never tokenized, or detached since the last renewal) — from
            // the customer's perspective this is identical to a declined charge, so it gets the
            // same grace/dunning treatment rather than silently stalling: without this the
            // subscription would stay `active` on a stale current_period_ends_at and get re-picked
            // by this command forever, never reaching past_due or ever cancelling.
            $subscription->recordRenewalFailure();

            return null;
        }

        $payment = Payment::create([
            'status' => PaymentStatus::Pending,
            'type' => PaymentType::Charge,
            'gateway' => $subscription->gateway,
            'amount' => $amount->amount,
            'currency' => $amount->currency,
            'converted_from_currency' => $resolved->convertedFromCurrency,
            'exchange_rate' => $resolved->exchangeRate,
            'exchange_rate_at' => $resolved->exchangeRateAt,
            'tenant_id' => $subscription->tenant_id,
            'payable_type' => $subscription->getMorphClass(),
            'payable_id' => $subscription->id,
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]);

        return [$payment, $method];
    }
}
