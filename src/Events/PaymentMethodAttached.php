<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\PaymentMethod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentMethodAttached
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PaymentMethod $paymentMethod) {}
}
