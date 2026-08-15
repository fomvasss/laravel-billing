<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Support\Money;

/** Optional — implement only if the gateway can refund via API (some require doing it manually in the bank's dashboard). */
interface RefundsPayments
{
    /**
     * Only the gateway API call — the child Payment row (type=refund) and the PaymentRefunded
     * event are BillingManager::refund()'s job, which is the entry point consumers should use.
     */
    public function refund(Payment $payment, ?Money $amount = null): PaymentResult;
}
