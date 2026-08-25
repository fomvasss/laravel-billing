<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Subscription;

/**
 * The ChargeOptions billing:process-recurring-charges hands the gateway for a renewal — the one
 * charge in the package nobody outside it gets to build options for. There's no request behind a
 * scheduled renewal and its Payable is the package's own Subscription row, so neither the caller
 * nor the HasReceiptItems auto-fill can supply the fiscal basket, description, email or customer
 * IP an ordinary charge carries. Bind an implementation of your own over the default one
 * (Support\DefaultRenewalChargeOptions) to supply them.
 */
interface RenewalChargeOptionsContract
{
    public function resolve(Subscription $subscription, Payment $payment): ChargeOptions;
}
