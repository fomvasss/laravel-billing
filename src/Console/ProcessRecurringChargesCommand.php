<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Contracts\TokenizesPaymentMethod;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\PaymentType;
use Fomvasss\Billing\Enums\SubscriptionStatus;
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
        $count = 0;

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->whereNotNull('gateway')
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', now())
            ->chunkById(200, function ($subscriptions) use ($billing, &$count) {
                foreach ($subscriptions as $subscription) {
                    if ($this->charge($subscription, $billing)) {
                        $count++;
                    }
                }
            });

        $this->info("Attempted {$count} recurring charge(s).");

        return self::SUCCESS;
    }

    protected function charge(Subscription $subscription, BillingManager $billing): bool
    {
        $driver = $billing->driver($subscription->gateway, $subscription->billable?->tenantId());

        if (! $driver instanceof TokenizesPaymentMethod) {
            return false; // gateway can't do off-session charges — nothing this command can do
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
            'currency_code' => $amount->currency,
            'converted_from_currency' => $resolved->convertedFromCurrency,
            'exchange_rate' => $resolved->exchangeRate,
            'exchange_rate_at' => $resolved->exchangeRateAt,
            'tenant_id' => $subscription->tenant_id,
            'payable_type' => Subscription::class,
            'payable_id' => $subscription->id,
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]);

        $billing->chargeWithMethod($payment, $method);

        return true;
    }
}
