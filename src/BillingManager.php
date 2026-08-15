<?php

declare(strict_types=1);

namespace Fomvasss\Billing;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Contracts\CurrencyConverterContract;
use Fomvasss\Billing\Contracts\HasReceiptItems;
use Fomvasss\Billing\Contracts\PaymentGatewayContract;
use Fomvasss\Billing\Contracts\RefundsPayments;
use Fomvasss\Billing\Contracts\SubscriptionGatewayContract;
use Fomvasss\Billing\Contracts\TokenizesPaymentMethod;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\ResolvedAmount;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Exceptions\NotSupportedException;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Support\DefaultWebhookResponder;
use Fomvasss\Billing\Support\Money;
use Illuminate\Support\Facades\Cache;

class BillingManager
{
    /** @var array<string, class-string<PaymentGatewayContract>> */
    protected array $drivers = [];

    /** @var array<string, class-string<\Fomvasss\Billing\Contracts\SignatureValidator>> */
    protected array $signatureValidators = [];

    /** @var array<string, class-string<\Fomvasss\Billing\Contracts\WebhookResponder>> */
    protected array $responders = [];

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

    /**
     * One call alongside extend() registers everything the incoming webhook route needs for this
     * gateway — no separate config file, no per-gateway route (a single wildcard route handles all
     * of them, resolved through this registry at request time).
     *
     * @param  class-string<\Fomvasss\Billing\Contracts\SignatureValidator>  $signatureValidator
     * @param  class-string<\Fomvasss\Billing\Contracts\WebhookResponder>|null  $responder  Defaults to a bare 200 — pass your own when the gateway requires a specific acknowledgment body (WayForPay does).
     */
    public function registerWebhook(string $name, string $signatureValidator, ?string $responder = null): static
    {
        $this->signatureValidators[$name] = $signatureValidator;
        $this->responders[$name] = $responder ?? DefaultWebhookResponder::class;

        return $this;
    }

    public function signatureValidatorFor(string $name): string
    {
        return $this->signatureValidators[$name] ?? throw BillingException::unknownGateway($name);
    }

    public function responderFor(string $name): string
    {
        return $this->responders[$name] ?? DefaultWebhookResponder::class;
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
            'webhook_url' => route('billing.webhook', ['gateway' => $name]),
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
     *
     * payment_url is ALWAYS a plain redirectable link here, regardless of whether the driver
     * returned $result->url or $result->form — a form-only gateway (LiqPay, currently the only
     * one) goes through storeCheckoutForm() so callers never have to branch on which one they got.
     *
     * $options->receiptItems auto-fills from $payment->payable->receiptItems() when the caller
     * didn't already set one explicitly and $payable implements HasReceiptItems — the fiscal
     * basket a driver like Monobank/LiqPay needs, without every caller repeating the same
     * "does this order implement HasReceiptItems" check themselves.
     */
    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $driver = $this->driver($payment->gateway, $payment->billable?->tenantId());

        if ($options->receiptItems === [] && $payment->payable instanceof HasReceiptItems) {
            $options = new ChargeOptions(
                receiptItems: $payment->payable->receiptItems(),
                customerEmail: $options->customerEmail,
                locale: $options->locale,
                description: $options->description,
                saveCard: $options->saveCard,
                successUrl: $options->successUrl,
                failUrl: $options->failUrl,
                webhookUrlParams: $options->webhookUrlParams,
                raw: $options->raw,
            );
        }

        $result = $driver->charge($payment, $options);

        $payment->fill([
            'external_id' => $result->externalId ?? $payment->external_id,
            'payment_url' => $result->url ?? $this->storeCheckoutForm($payment, $result),
            'payment_url_expires_at' => $result->expiresAt,
        ])->save();

        return $result;
    }

    /**
     * Bridges PaymentResult::$form into a plain URL — CheckoutFormController renders whatever's
     * cached here as a self-submitting HTML form. Cached, not recomputed on each visit: recomputing
     * would assume charge() is side-effect-free for every current AND future form-returning driver,
     * which isn't safe to bake in here (a hypothetical driver could create a real gateway-side
     * session inside charge() the same way Stripe's checkout() does).
     */
    protected function storeCheckoutForm(Payment $payment, PaymentResult $result): ?string
    {
        if ($result->form === null) {
            return null;
        }

        Cache::put("billing.checkout_form.{$payment->id}", $result->form, $result->expiresAt ?? now()->addHour());

        return route('billing.checkout-form', $payment);
    }

    /** Same orchestration as charge(), for a saved payment method instead of a redirect/form. */
    public function chargeWithMethod(Payment $payment, PaymentMethod $method, array $options = []): PaymentResult
    {
        $driver = $this->driver($payment->gateway, $payment->billable?->tenantId());

        if (! $driver instanceof TokenizesPaymentMethod) {
            throw NotSupportedException::forCapability($payment->gateway, TokenizesPaymentMethod::class);
        }

        $result = $driver->chargePaymentMethod($payment, $method, $options);

        $payment->fill([
            'external_id' => $result->externalId ?? $payment->external_id,
            'payment_url' => $result->url,
            'payment_url_expires_at' => $result->expiresAt,
        ])->save();

        return $result;
    }

    /**
     * The 4-step order from "Валюти" in the plan: (1) $price's own currency already accepted by
     * $gateway → as-is; (2) a sibling Price of the same Plan+gateway in an accepted currency →
     * that one instead; (3) CurrencyConverterContract bound → convert; (4) none of the above →
     * BillingException::unsupportedCurrency(). Only resolves the CURRENCY/per-unit rate — scaling
     * by qty (licensed) or current_usage (metered) is the caller's job (Price::amountForSubscription()),
     * done on top of whatever Money this returns.
     */
    public function resolveChargeAmount(Price $price, string $gateway): ResolvedAmount
    {
        $class = $this->drivers[$gateway] ?? throw BillingException::unknownGateway($gateway);
        $supported = $class::supportedCurrencies();

        if (in_array($price->currency_code, $supported, true)) {
            return new ResolvedAmount(new Money($price->amount, $price->currency_code));
        }

        $sibling = $price->plan
            ->prices()
            ->where('gateway', $gateway)
            ->whereIn('currency_code', $supported)
            ->first();

        if ($sibling !== null) {
            return new ResolvedAmount(new Money($sibling->amount, $sibling->currency_code));
        }

        if (app()->bound(CurrencyConverterContract::class)) {
            $toCurrency = $supported[0] ?? throw BillingException::unsupportedCurrency($price->currency_code, $gateway);

            $original = new Money($price->amount, $price->currency_code);
            $converted = app(CurrencyConverterContract::class)->convert($original, $toCurrency);

            return new ResolvedAmount(
                money: $converted,
                convertedFromCurrency: $price->currency_code,
                exchangeRate: $original->amount > 0 ? $converted->amount / $original->amount : null,
                exchangeRateAt: now(),
            );
        }

        throw BillingException::unsupportedCurrency($price->currency_code, $gateway);
    }
}
