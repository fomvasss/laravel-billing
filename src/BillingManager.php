<?php

declare(strict_types=1);

namespace Fomvasss\Billing;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Contracts\PaymentGatewayContract;
use Fomvasss\Billing\Contracts\RefundsPayments;
use Fomvasss\Billing\Contracts\SubscriptionGatewayContract;
use Fomvasss\Billing\Contracts\TokenizesPaymentMethod;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Models\Payment;

class BillingManager
{
    /** @var array<string, class-string<PaymentGatewayContract>> */
    protected array $drivers = [];

    /**
     * @param  class-string<PaymentGatewayContract>  $class  FQCN, not a closure — lets BillingManager
     *   call static methods (label(), supportedCurrencies(), credentialFields()) via gateways()
     *   without ever instantiating the driver (no credentials needed just to list it).
     */
    public function extend(string $name, string $class): static
    {
        if ($name === 'fake' && ! app()->environment(['local', 'testing'])) {
            throw new BillingException('The "fake" billing gateway can only be registered in local/testing environments.');
        }

        $this->drivers[$name] = $class;

        return $this;
    }

    public function driver(string $name, ?string $tenantId = null): PaymentGatewayContract
    {
        $class = $this->drivers[$name] ?? throw BillingException::unknownGateway($name);

        $credentials = app(CredentialResolverContract::class)->resolve($name, $tenantId);

        return app()->makeWith($class, [
            'credentials' => $credentials,
            'gatewayName' => $name,
        ]);
    }

    /** @return array<string, array{key: string, label: string, currencies: array, credential_fields: array, webhook_url: string, capabilities: array}> */
    public function gateways(): array
    {
        return collect($this->drivers)->mapWithKeys(fn (string $class, string $name) => [$name => [
            'key' => $name,
            'label' => $class::label(),
            'currencies' => $class::supportedCurrencies(),
            'credential_fields' => $class::credentialFields(),
            'webhook_url' => route("webhook-client-{$name}"),
            'capabilities' => [
                'refunds' => is_subclass_of($class, RefundsPayments::class),
                'subscriptions' => is_subclass_of($class, SubscriptionGatewayContract::class),
                'tokenization' => is_subclass_of($class, TokenizesPaymentMethod::class),
            ],
        ]])->all();
    }

    public function gateway(string $name): ?array
    {
        return $this->gateways()[$name] ?? null;
    }

    /**
     * The orchestration a bare $driver->charge() call can't do on its own: resolves the driver
     * for $payment->gateway (with $payment->billable's tenant, for dynamic per-tenant credentials),
     * calls it, then writes the result's external_id/payment_url/payment_url_expires_at back onto
     * $payment — the same three columns PaymentRedirectController reads on a repeat visit.
     */
    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $driver = $this->driver($payment->gateway, $payment->billable?->tenantId());

        $result = $driver->charge($payment, $options);

        $payment->fill([
            'external_id' => $result->externalId ?? $payment->external_id,
            'payment_url' => $result->url,
            'payment_url_expires_at' => $result->expiresAt,
        ])->save();

        return $result;
    }
}
