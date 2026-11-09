<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The merchant withdrew an unpaid charge (Billing::voidPayment()). Separate from PaymentCanceled on
 * purpose: that one means the checkout ran out on its own and, for a renewal, starts dunning — a
 * voided charge is a decision, not a missed payment.
 */
class PaymentVoided
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}
