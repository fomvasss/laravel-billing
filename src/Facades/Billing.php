<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Facades;

use Fomvasss\Billing\BillingManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static BillingManager extend(string $name, string $class)
 * @method static \Fomvasss\Billing\Contracts\PaymentGatewayContract driver(string $name, ?string $tenantId = null)
 * @method static array gateways()
 * @method static array|null gateway(string $name)
 */
class Billing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BillingManager::class;
    }
}
