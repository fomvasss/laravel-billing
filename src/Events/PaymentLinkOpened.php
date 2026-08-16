<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone opened the permanent pay link (billing.pay route) for this payment — fired on every
 * visit, before any redirect/re-issue happens. An analytics/sales signal ("the customer opened
 * the invoice link twice but never paid"), nothing more: opening proves no intent and no payment.
 */
class PaymentLinkOpened
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}
