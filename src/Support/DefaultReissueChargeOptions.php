<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Contracts\ReissueChargeOptionsContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Models\Payment;

/**
 * Empty options — a re-issued checkout reaches the gateway exactly as it did before this contract
 * existed. Deliberately not "guess what the first charge probably carried": the package never
 * stored those options, and inventing a saveCard or a basket line here would be a decision made
 * on the consumer's behalf about money.
 */
class DefaultReissueChargeOptions implements ReissueChargeOptionsContract
{
    public function resolve(Payment $payment): ChargeOptions
    {
        return new ChargeOptions();
    }
}
