<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRefunded
{
    use Dispatchable;
    use SerializesModels;

    /** $payment — the refund row itself (type=refund), not the original charge. */
    public function __construct(public readonly Payment $payment) {}
}
