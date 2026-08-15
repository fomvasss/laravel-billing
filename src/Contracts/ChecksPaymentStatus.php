<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Models\Payment;

/** Optional, like RefundsPayments — not every gateway offers a status-polling endpoint. Used as a fallback by ReconcilePendingPaymentsJob. */
interface ChecksPaymentStatus
{
    /** Same DTO as a webhook would produce. */
    public function checkStatus(Payment $payment): WebhookResult;
}
