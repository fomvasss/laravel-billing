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
 * @method static \Fomvasss\Billing\DTO\PaymentResult charge(\Fomvasss\Billing\Models\Payment $payment, \Fomvasss\Billing\DTO\ChargeOptions $options = new \Fomvasss\Billing\DTO\ChargeOptions())
 * @method static \Fomvasss\Billing\DTO\PaymentResult chargeWithMethod(\Fomvasss\Billing\Models\Payment $payment, \Fomvasss\Billing\Models\PaymentMethod $method, array $options = [])
 * @method static \Fomvasss\Billing\DTO\ResolvedAmount resolveChargeAmount(\Fomvasss\Billing\Models\Price $price, string $gateway)
 */
class Billing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BillingManager::class;
    }
}
