<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways;

use Fomvasss\Billing\Contracts\PaymentGatewayContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
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
        // One wildcard route for every gateway (WebhookController) — {gateway} fills the segment,
        // any other keys in $options->webhookUrlParams become query string extras.
        return route('billing.webhook', ['gateway' => $this->gatewayName, ...$options->webhookUrlParams]);
    }

    /**
     * Where the gateway sends the browser after checkout. An explicit ChargeOptions URL is used
     * as-is; otherwise the gateway gets the package's return route (billing.return), which fires
     * CheckoutReturned and forwards to config('billing.return_urls.*') with ?payment={id} — that
     * hop is what absorbs WayForPay/Hutko's POST-style returns and serves frontend/SPA pages.
     */
    protected function successUrl(Payment $payment, ChargeOptions $options): string
    {
        return $options->successUrl ?? $this->returnUrl($payment, 'success', $options);
    }

    protected function failUrl(Payment $payment, ChargeOptions $options): string
    {
        return $options->failUrl ?? $this->returnUrl($payment, 'failed', $options);
    }

    private function returnUrl(Payment $payment, string $outcome, ChargeOptions $options): string
    {
        if (config("billing.return_urls.{$outcome}") === null) {
            throw BillingException::missingReturnUrl($outcome);
        }

        // returnParams ride as query on the proxy URL; CheckoutReturnController forwards them onto
        // the final return_urls.* redirect, so the frontend page gets them alongside ?payment=.
        return route('billing.return', ['payment' => $payment, 'outcome' => $outcome, ...$options->returnParams]);
    }

    /**
     * A signed "paid" callback whose sum/currency doesn't match this Payment row is NOT proof this
     * row was paid — the classic case is a stale checkout link paid after the amount was edited
     * and charge() re-issued. The driver refuses to mark paid on a mismatch; the row stays pending
     * for reconciliation/manual review (the stored webhook call keeps the full payload).
     */
    protected function paidAmountMismatch(Payment $payment, ?int $amountMinor, ?string $currency): bool
    {
        $mismatch = ($amountMinor !== null && $amountMinor !== $payment->amount)
            || ($currency !== null && strcasecmp($currency, $payment->currency) !== 0);

        if ($mismatch) {
            Log::warning("Billing [{$this->gatewayName}]: paid webhook amount/currency mismatch — leaving payment pending", [
                'payment_id' => $payment->id,
                'expected' => [$payment->amount, $payment->currency],
                'received' => [$amountMinor, $currency],
            ]);
        }

        return $mismatch;
    }

    /**
     * Shared by every TokenizesPaymentMethod driver — demotes the previous default PaymentMethod
     * for this billable+gateway, then upserts by (gateway, external_customer_id, external_id).
     * Does NOT dispatch PaymentMethodAttached — callers need different dispatch timing (a direct
     * attachPaymentMethod() call dispatches itself; a webhook-driven attach either returns a
     * WebhookResult for WebhookResultDispatcher to dispatch, or — when the same webhook call also
     * carries the payment-status WebhookResult, as LiqPay/WayForPay's does — dispatches directly).
     */
    protected function persistPaymentMethod(
        string $billableType,
        string $billableId,
        ?string $tenantId,
        string $externalCustomerId,
        string $externalId,
        ?string $last4 = null,
        ?string $brand = null,
        ?\DateTimeInterface $expiresAt = null,
    ): PaymentMethod {
        PaymentMethod::query()
            ->where('billable_type', $billableType)
            ->where('billable_id', $billableId)
            ->where('gateway', $this->gatewayName)
            ->update(['is_default' => false]);

        return PaymentMethod::updateOrCreate(
            ['gateway' => $this->gatewayName, 'external_customer_id' => $externalCustomerId, 'external_id' => $externalId],
            [
                'type' => 'card',
                'brand' => $brand,
                'last4' => $last4,
                'expires_at' => $expiresAt,
                'is_default' => true,
                'tenant_id' => $tenantId,
                'billable_type' => $billableType,
                'billable_id' => $billableId,
            ],
        );
    }
}
