<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from Subscription::reportUsage() when cumulative usage crosses price.included_units.
 * The consumer decides the reaction in a listener: block further calls, notify, or charge an
 * overage via TokenizesPaymentMethod::chargePaymentMethod() — nothing the package does automatically.
 */
class UsageLimitReached
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Subscription $subscription) {}
}
