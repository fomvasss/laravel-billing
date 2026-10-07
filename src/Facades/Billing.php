<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Facades;

use Fomvasss\Billing\BillingManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static BillingManager extend(string $name, string $class)
 * @method static \Fomvasss\Billing\Contracts\PaymentGatewayContract driver(?string $name, ?string $tenantId = null)
 * @method static array gateways()
 * @method static array supportedCurrencies(string $gateway)
 * @method static \Fomvasss\Billing\DTO\GatewayHealth health(string $gateway, ?string $tenantId = null)
 * @method static array|null gateway(string $name)
 * @method static \Fomvasss\Billing\DTO\PaymentResult charge(\Fomvasss\Billing\Models\Payment $payment, \Fomvasss\Billing\DTO\ChargeOptions $options = new \Fomvasss\Billing\DTO\ChargeOptions())
 * @method static \Fomvasss\Billing\DTO\PaymentResult startSubscription(\Fomvasss\Billing\Models\Payment $payment, \Fomvasss\Billing\DTO\ChargeOptions $options = new \Fomvasss\Billing\DTO\ChargeOptions())
 * @method static \Fomvasss\Billing\DTO\PaymentResult chargeWithMethod(\Fomvasss\Billing\Models\Payment $payment, \Fomvasss\Billing\Models\PaymentMethod $method, \Fomvasss\Billing\DTO\ChargeOptions $options = new \Fomvasss\Billing\DTO\ChargeOptions())
 * @method static \Fomvasss\Billing\Models\Payment refund(\Fomvasss\Billing\Models\Payment $payment, ?\Fomvasss\Billing\Support\Money $amount = null)
 * @method static \Fomvasss\Billing\DTO\ResolvedAmount resolveChargeAmount(\Fomvasss\Billing\Models\Price $price, string $gateway)
 * @method static \Fomvasss\Billing\Models\Invoice issueInvoice(\Fomvasss\Billing\Models\Payment $payment, ?\Fomvasss\Billing\DTO\BillingDetails $buyer = null, array $extra = [], ?\DateTimeInterface $dueAt = null, ?string $locale = null, ?\Fomvasss\Billing\DTO\BillingDetails $seller = null)
 * @method static \Fomvasss\Billing\Models\Invoice issueReceipt(\Fomvasss\Billing\Models\Payment $payment, ?\Fomvasss\Billing\DTO\BillingDetails $buyer = null, array $extra = [], ?string $locale = null, ?\Fomvasss\Billing\DTO\BillingDetails $seller = null)
 * @method static \Fomvasss\Billing\Models\Invoice voidInvoice(\Fomvasss\Billing\Models\Invoice $invoice)
 * @method static \Fomvasss\Billing\DTO\InvoiceDocument invoiceDocument(\Fomvasss\Billing\Models\Invoice $invoice)
 * @method static string renderInvoice(\Fomvasss\Billing\Models\Invoice $invoice)
 * @method static string invoicePdf(\Fomvasss\Billing\Models\Invoice $invoice)
 * @method static string invoicePdfUrl(\Fomvasss\Billing\Models\Invoice $invoice, ?int $ttlMinutes = null)
 */
class Billing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BillingManager::class;
    }
}
