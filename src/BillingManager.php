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
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\PaymentType;
use Fomvasss\Billing\Events\PaymentRefunded;
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

    /**
     * The currencies a gateway accepts. The driver's static supportedCurrencies() is the default;
     * config('billing.gateways.{name}.currencies') overrides it — narrow it to what YOUR merchant
     * account actually has enabled, or extend it when the driver's hardcoded list lags behind the
     * gateway (no gateway exposes a "list my currencies" API, so the driver's list is always an
     * approximation).
     */
    public function supportedCurrencies(string $gateway): array
    {
        $class = $this->drivers[$gateway] ?? throw BillingException::unknownGateway($gateway);

        $override = config("billing.gateways.{$gateway}.currencies");

        return is_array($override) && $override !== []
            ? array_map(strtoupper(...), $override)
            : $class::supportedCurrencies();
    }

    /** @return array<string, array{key: string, label: string, currencies: array, credential_fields: array, webhook_url: string, webhook_requires_dashboard_setup: bool, capabilities: array}> */
    public function gateways(): array
    {
        return collect($this->drivers)->mapWithKeys(fn (string $class, string $name) => [$name => [
            'key' => $name,
            'label' => $class::label(),
            'currencies' => $this->supportedCurrencies($name),
            'credential_fields' => $class::credentialFields(),
            'webhook_url' => route('billing.webhook', ['gateway' => $name]),
            // true = paste webhook_url into the gateway's dashboard; false = the driver already
            // sends it in every charge request, nothing to configure manually
            'webhook_requires_dashboard_setup' => $class::requiresDashboardWebhook(),
            'capabilities' => [
                'refunds' => is_subclass_of($class, RefundsPayments::class),
                'subscriptions' => is_subclass_of($class, SubscriptionGatewayContract::class),
                'tokenization' => is_subclass_of($class, TokenizesPaymentMethod::class),
                'health' => is_subclass_of($class, \Fomvasss\Billing\Contracts\ChecksGatewayHealth::class),
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
     * $payment.
     *
     * payment_url is ALWAYS a plain redirectable link here, regardless of whether the driver
     * returned $result->url or $result->form — a form-only gateway (LiqPay, currently the only
     * one) gets its form cached and served by CheckoutFormController so callers never have to
     * branch on which one they got. Cached, not recomputed per visit: recomputing would assume
     * charge() is side-effect-free for every current AND future form-returning driver, which isn't
     * safe to bake in. The cache TTL and payment_url_expires_at are the same value on purpose —
     * hasActivePaymentUrl() must not say "alive" for a link whose cached form is already gone.
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
            $options = $options->withReceiptItems($payment->payable->receiptItems());
        }

        $this->assertReceiptItemsMatchAmount($payment, $options);

        $result = $driver->charge($payment, $options);

        $url = $result->url;
        $urlExpiresAt = $result->expiresAt;

        if ($url === null && $result->form !== null) {
            $urlExpiresAt ??= now()->addHour();
            Cache::put("billing.checkout_form.{$payment->id}", $result->form, $urlExpiresAt);
            $url = route('billing.checkout-form', $payment);
        }

        $payment->fill([
            'external_id' => $result->externalId ?? $payment->external_id,
            'payment_url' => $url,
            'payment_url_expires_at' => $urlExpiresAt,
            // Kept for support/debugging: without it the gateway's own account of what it did with
            // this charge exists nowhere (a webhook only ever reports the outcome).
            'raw_response' => $result->raw !== [] ? $result->raw : $payment->raw_response,
        ])->save();

        return $result;
    }

    /**
     * Same orchestration as charge(), for a saved payment method instead of a redirect/form —
     * including the same $options->receiptItems auto-fill (see charge()'s docblock). A scheduled
     * subscription renewal (ProcessRecurringChargesCommand) never gets one this way: its Payable is
     * always the package's own Subscription row, which deliberately does NOT implement
     * HasReceiptItems (the basket total would have to reconstruct pricing_type/currency-conversion
     * math the package doesn't want to guess at for a fiscal document) — pass receiptItems
     * yourself when calling chargeWithMethod() directly if a renewal needs a receipt.
     */
    public function chargeWithMethod(Payment $payment, PaymentMethod $method, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        // Cheap guards against a caller-side mixup that would debit the wrong card: the method
        // must be from the same gateway AND belong to the same billable as the payment.
        if ($method->gateway !== $payment->gateway) {
            throw new BillingException("Payment method belongs to gateway \"{$method->gateway}\", the payment to \"{$payment->gateway}\".");
        }

        if ($method->billable_type !== $payment->billable_type || (string) $method->billable_id !== (string) $payment->billable_id) {
            throw new BillingException("Payment method {$method->id} does not belong to payment {$payment->id}'s billable.");
        }

        $driver = $this->driver($payment->gateway, $payment->billable?->tenantId());

        if (! $driver instanceof TokenizesPaymentMethod) {
            throw NotSupportedException::forCapability($payment->gateway, TokenizesPaymentMethod::class);
        }

        if ($options->receiptItems === [] && $payment->payable instanceof HasReceiptItems) {
            $options = $options->withReceiptItems($payment->payable->receiptItems());
        }

        $this->assertReceiptItemsMatchAmount($payment, $options);

        $result = $driver->chargePaymentMethod($payment, $method, $options);

        $payment->fill([
            'external_id' => $result->externalId ?? $payment->external_id,
            'payment_url' => $result->url,
            'payment_url_expires_at' => $result->expiresAt,
            // The decline reason lives here and nowhere else: an off-session charge the gateway
            // refuses synchronously (Stripe's error.code, Hutko's error_message, WayForPay's
            // reasonCode) never produces a webhook to carry it.
            'raw_response' => $result->raw !== [] ? $result->raw : $payment->raw_response,
        ])->save();

        return $result;
    }

    /**
     * A basket that doesn't add up to the Payment's own amount is a bug worth stopping the charge
     * over, not a rounding curiosity: Stripe bills the sum of its line_items rather than our
     * amount, so the customer would be charged something other than what the row says — and the
     * webhook, checking the callback against amount, would then refuse to mark it paid, leaving a
     * pending row for money that actually left the customer's card.
     */
    protected function assertReceiptItemsMatchAmount(Payment $payment, ChargeOptions $options): void
    {
        if ($options->receiptItems === []) {
            return;
        }

        $total = 0;

        foreach ($options->receiptItems as $item) {
            $total += (int) round($item['unitAmount'] * $item['qty']);
        }

        if ($total !== $payment->amount) {
            throw new BillingException(
                "Receipt items for payment {$payment->id} total {$total}, the payment is {$payment->amount} {$payment->currency}."
            );
        }
    }

    /**
     * Live "credentials valid + API reachable" probe — for a settings-UI "test connection" button
     * or a monitoring cron (see the billing:health command). Never a guarantee about the next
     * charge; never has side effects on the gateway.
     */
    public function health(string $gateway, ?string $tenantId = null): \Fomvasss\Billing\DTO\GatewayHealth
    {
        $driver = $this->driver($gateway, $tenantId);

        if (! $driver instanceof \Fomvasss\Billing\Contracts\ChecksGatewayHealth) {
            throw NotSupportedException::forCapability($gateway, \Fomvasss\Billing\Contracts\ChecksGatewayHealth::class);
        }

        return $driver->healthCheck();
    }

    /**
     * The orchestration half of RefundsPayments — drivers only make the API call; this creates the
     * child Payment row (type=refund, parent_payment_id) and dispatches PaymentRefunded, so
     * refundedAmount() and the event actually reflect what happened. $amount null = refund the
     * unrefunded remainder in full.
     */
    public function refund(Payment $payment, ?Money $amount = null): Payment
    {
        $driver = $this->driver($payment->gateway, $payment->billable?->tenantId());

        if (! $driver instanceof RefundsPayments) {
            throw NotSupportedException::forCapability($payment->gateway, RefundsPayments::class);
        }

        // "How much is left to refund" is read, checked and then written by a THIRD statement (the
        // child row) — two concurrent calls (an impatient double click, a retried job) would both
        // pass the remainder check against the same stale total and both send money back. The lock
        // lives in the app's cache store: give the app a shared one (redis/memcached/database), an
        // array/file store only serializes calls within a single process.
        $lock = Cache::lock("billing:refund:{$payment->id}", 60);

        if (! $lock->get()) {
            throw new BillingException("Another refund for payment {$payment->id} is already in progress.");
        }

        try {
            return $this->processRefund($driver, $payment->fresh(), $amount);
        } finally {
            $lock->release();
        }
    }

    protected function processRefund(RefundsPayments $driver, Payment $payment, ?Money $amount): Payment
    {
        if (! $payment->isPaid() || $payment->isRefund()) {
            throw new BillingException("Only a paid charge can be refunded (payment {$payment->id} is {$payment->type->value}/{$payment->status->value}).");
        }

        $money = $amount ?? new Money($payment->amount - $payment->refundedAmount(), $payment->currency);

        if ($money->currency !== $payment->currency) {
            throw new BillingException("Refund currency \"{$money->currency}\" does not match the charge's \"{$payment->currency}\".");
        }

        if ($money->amount <= 0 || $money->amount + $payment->refundedAmount() > $payment->amount) {
            throw new BillingException("Refund of {$money->amount} exceeds the refundable remainder of payment {$payment->id}.");
        }

        // Always an explicit amount, even for "full": with earlier partial refunds, a null/full
        // gateway-side refund and our computed remainder would disagree. A gateway that refuses the
        // refund throws from here — the child row below is only ever written for money that is
        // actually on its way back.
        $result = $driver->refund($payment, $money);

        $refund = Payment::create([
            'status' => PaymentStatus::Paid,
            'type' => PaymentType::Refund,
            'gateway' => $payment->gateway,
            'amount' => $money->amount,
            'currency' => $money->currency,
            'external_id' => $result->externalId,
            'raw_response' => $result->raw,
            'tenant_id' => $payment->tenant_id,
            'payable_type' => $payment->payable_type,
            'payable_id' => $payment->payable_id,
            'billable_type' => $payment->billable_type,
            'billable_id' => $payment->billable_id,
            'parent_payment_id' => $payment->id,
        ]);

        PaymentRefunded::dispatch($refund);

        return $refund;
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
        $supported = $this->supportedCurrencies($gateway);

        if (in_array($price->currency, $supported, true)) {
            return new ResolvedAmount(new Money($price->amount, $price->currency));
        }

        // A gateway-specific sibling wins; a generic (gateway=null) price in an accepted currency
        // is still better than paying for a conversion. "Sibling" means the SAME offer in another
        // currency, so the billing cycle and pricing model have to match: without that, a plan
        // priced monthly in UAH and yearly in USD would quietly bill a monthly subscription the
        // yearly amount. Retired prices are excluded for the same reason.
        $siblings = fn () => $price->plan
            ->prices()
            ->whereIn('currency', $supported)
            ->where('interval', $price->interval)
            // ?? 1 mirrors the column default: a Price created and used without a round trip to
            // the database still carries null here, and null would match nothing.
            ->where('interval_count', $price->interval_count ?? 1)
            ->where('pricing_type', $price->pricing_type)
            ->where('is_active', true);

        $sibling = $siblings()->where('gateway', $gateway)->first()
            ?? $siblings()->whereNull('gateway')->first();

        if ($sibling !== null) {
            return new ResolvedAmount(new Money($sibling->amount, $sibling->currency));
        }

        if (app()->bound(CurrencyConverterContract::class)) {
            $toCurrency = $supported[0] ?? throw BillingException::unsupportedCurrency($price->currency, $gateway);

            $original = new Money($price->amount, $price->currency);
            $converted = app(CurrencyConverterContract::class)->convert($original, $toCurrency);

            return new ResolvedAmount(
                money: $converted,
                convertedFromCurrency: $price->currency,
                exchangeRate: $original->amount > 0 ? $converted->amount / $original->amount : null,
                exchangeRateAt: now(),
            );
        }

        throw BillingException::unsupportedCurrency($price->currency, $gateway);
    }
}
