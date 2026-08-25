<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Contracts\RenewalChargeOptionsContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Subscription;

/**
 * Empty options — a renewal reaches the gateway exactly as it did before this contract existed.
 *
 * With billing.renewal.receipt_items on, it also builds the one basket line the package can state
 * without inventing anything: the whole payment as a single item named after the plan. That is
 * deliberately the ceiling of a generic implementation — per-seat lines, tax rates, UKTZED codes
 * and everything else a real fiscal document may need belong to a resolver of your own.
 */
class DefaultRenewalChargeOptions implements RenewalChargeOptionsContract
{
    public function resolve(Subscription $subscription, Payment $payment): ChargeOptions
    {
        if (! config('billing.renewal.receipt_items', false)) {
            return new ChargeOptions();
        }

        $price = $subscription->price;

        return new ChargeOptions(receiptItems: [[
            'name' => $price->meta['receipt_name'] ?? $price->plan->name,
            // One line at qty 1 for the payment's full amount, never qty × unit price: a licensed
            // price charges whole seats and a metered one a fractional quantity, while unitAmount
            // is a minor-unit integer — a total that doesn't divide evenly would come back off by
            // a kopiyka and fail the receipt-total check before the gateway ever sees it.
            'qty' => 1,
            'unitAmount' => $payment->amount,
        ]]);
    }
}
