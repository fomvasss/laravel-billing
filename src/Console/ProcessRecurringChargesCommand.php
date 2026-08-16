<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Contracts\TokenizesPaymentMethod;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\PaymentType;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Support\Money;
use Illuminate\Console\Command;

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

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->whereNotNull('gateway')
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', now())
            // Dunning pacing: a failed attempt stamps next_retry_at (retry_interval_hours ahead) —
            // until then the hourly run leaves the subscription alone.
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
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
    protected function finalizeDueCancellations(): void
    {
        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
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
            return false;
        }

        $method = PaymentMethod::query()
            ->where('billable_type', $subscription->billable_type)
            ->where('billable_id', $subscription->billable_id)
            ->where('gateway', $subscription->gateway)
            ->where('is_default', true)
            ->first();

        if ($method === null) {
            return false; // no saved method to charge — surfaces via the usual grace/dunning cycle on the next attempt, not here
        }

        $price = $subscription->price;
        $resolved = $billing->resolveChargeAmount($price, $subscription->gateway);
        $multiplier = $price->chargeMultiplier($subscription);

        $amount = new Money((int) round($resolved->money->amount * $multiplier), $resolved->money->currency);

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

        $billing->chargeWithMethod($payment, $method);

        return true;
    }
}
