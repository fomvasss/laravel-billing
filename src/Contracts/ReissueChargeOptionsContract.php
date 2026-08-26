<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Models\Payment;

/**
 * The ChargeOptions the permanent pay link (billing.pay) hands the gateway when it has to issue a
 * FRESH checkout for a payment whose own link went stale — the sibling of
 * RenewalChargeOptionsContract, and for the same reason: nobody outside the package gets to build
 * options for that charge.
 *
 * Without it a re-issue silently drops whatever the original charge carried. Two of those are not
 * cosmetic: `saveCard` (no card token means the subscription can never renew, and nothing
 * complains) and the fiscal basket (a receipt with no line items). The HasReceiptItems auto-fill
 * only covers a payable that implements it, which the package's own Subscription does not.
 *
 * The default (Support\DefaultReissueChargeOptions) returns empty options — the behaviour every
 * version before this contract had. Bind your own to carry the original intent across.
 */
interface ReissueChargeOptionsContract
{
    public function resolve(Payment $payment): ChargeOptions;
}
