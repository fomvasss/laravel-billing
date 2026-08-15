<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Support\Money;

/** Optional — implement only if the gateway can refund via API (some require doing it manually in the bank's dashboard). */
interface RefundsPayments
{
    /** Returns the PaymentResult for a new Payment row (type=refund), not a separate entity. */
    public function refund(Payment $payment, ?Money $amount = null): PaymentResult;
}
