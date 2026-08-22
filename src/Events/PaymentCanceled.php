<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The charge will never complete — the gateway voided/expired it, or reconciliation wrote off a
 * checkout nobody finished. Distinct from PaymentFailed (the customer's card was actually tried
 * and refused): both mean "not paid", but only one is worth telling the customer about.
 */
class PaymentCanceled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}
