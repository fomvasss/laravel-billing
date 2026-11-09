<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\Models\Payment;

/** Optional — implement only if the gateway can invalidate an unpaid checkout via API. */
interface VoidsPayments
{
    /**
     * Only the gateway API call: after it returns, the checkout behind $payment->external_id can no
     * longer be paid. Return normally when it was already dead (expired, declined); throw when the
     * gateway reports it paid or still processing — BillingManager::voidPayment() then leaves the
     * row as it was. The row itself (status, voided_at) and the PaymentVoided event are
     * voidPayment()'s job.
     */
    public function void(Payment $payment): void;
}
