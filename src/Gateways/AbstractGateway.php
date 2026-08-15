<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways;

use Fomvasss\Billing\Contracts\PaymentGatewayContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Exceptions\BillingException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Optional base class — not required (a driver may `implements PaymentGatewayContract` directly),
 * just less boilerplate: a shared debug log instead of copy-pasting a per-driver debug config key,
 * and the webhookUrl()/successUrl()/failUrl() helpers every driver ends up needing.
 */
abstract class AbstractGateway implements PaymentGatewayContract
{
    public function __construct(
        protected readonly array $credentials,
        /** e.g. "monobank" — injected by BillingManager::driver(), not duplicated as a method on the driver. */
        protected readonly string $gatewayName,
    ) {}

    public static function label(): string
    {
        return Str::headline(class_basename(static::class));
    }

    protected function log(string $method, array $context = []): void
    {
        if (config('billing.debug')) {
            Log::debug(static::class . '::' . $method, $context);
        }
    }

    protected function webhookUrl(ChargeOptions $options): string
    {
        return route("billing.webhook.{$this->gatewayName}", $options->webhookUrlParams);
    }

    protected function successUrl(ChargeOptions $options): string
    {
        return $options->successUrl
            ?? config('billing.return_urls.success')
            ?? throw BillingException::missingReturnUrl('success');
    }

    protected function failUrl(ChargeOptions $options): string
    {
        return $options->failUrl
            ?? config('billing.return_urls.failed')
            ?? throw BillingException::missingReturnUrl('failed');
    }
}
