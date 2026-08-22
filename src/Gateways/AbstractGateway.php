<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways;

use Fomvasss\Billing\Contracts\PaymentGatewayContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Support\Money;
use Illuminate\Support\Facades\DB;
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

    /** Most gateways take the callback URL per charge request — Stripe-style dashboard-only delivery overrides this. */
    public static function requiresDashboardWebhook(): bool
    {
        return false;
    }

    /**
     * Checkout-link TTL each driver passes to its gateway (Monobank `validity`, WayForPay
     * `orderLifetime`, Hutko `lifetime`, LiqPay's cached form) and mirrors into
     * PaymentResult::$expiresAt → payments.payment_url_expires_at. One config key everywhere
     * (`link_ttl_minutes`), per-driver default. Stripe is the exception: its session TTL comes
     * back from the API response instead.
     */
    protected function linkTtlMinutes(int $default = 1440): int
    {
        return (int) ($this->credentials['link_ttl_minutes'] ?? $default);
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
     * Shared shape for ChecksGatewayHealth drivers: run the probe, time it, translate any throw
     * into a down() result — a health check itself must never explode.
     *
     * @param  callable(): ?string  $probe  Performs the gateway call; returns the "up" detail
     *   message (merchant name etc.) or throws with the failure reason.
     */
    protected function probeHealth(callable $probe): \Fomvasss\Billing\DTO\GatewayHealth
    {
        $start = microtime(true);

        try {
            $message = $probe();

            return \Fomvasss\Billing\DTO\GatewayHealth::up($message, round((microtime(true) - $start) * 1000, 1));
        } catch (\Throwable $exception) {
            return \Fomvasss\Billing\DTO\GatewayHealth::down($exception->getMessage(), round((microtime(true) - $start) * 1000, 1));
        }
    }

    /**
     * Payment::find() for a gateway-supplied reference. The reference is untrusted (another
     * integration on the same merchant account can post its own order ids) — MySQL just finds
     * nothing for a non-UUID string, but Postgres throws casting it to the uuid PK column, so the
     * UUID check is what keeps "unknown references are Ignored, not failed jobs" true on both.
     */
    protected function findPaymentByReference(mixed $reference): ?Payment
    {
        return is_scalar($reference) && \Illuminate\Support\Str::isUuid((string) $reference)
            ? Payment::find((string) $reference)
            : null;
    }

    /**
     * `['fee' => <minor units>]` when the gateway reported its commission in a callback/status
     * response, `[]` otherwise — merged into the driver's Payment update so an unreported fee
     * stays null (unknown), never a guessed 0. $decimal for gateways whose amounts are decimal
     * strings (LiqPay, WayForPay); is_numeric (not isset) because Hutko sends "" when unset.
     * A real 0 still records as "known, zero commission".
     */
    protected function feeFrom(mixed $value, bool $decimal = false): array
    {
        return is_numeric($value)
            ? ['fee' => $decimal ? (int) round((float) $value * 100) : (int) $value]
            : [];
    }

    /**
     * A "paid" outcome whose sum/currency doesn't match this Payment row is NOT proof this row was
     * paid — the classic case is a stale checkout link paid after the amount was edited and
     * charge() re-issued. The driver refuses to mark paid on a mismatch; the row stays pending for
     * manual review (the stored webhook call keeps the full payload). Every checkStatus() applies
     * it too: otherwise reconciliation would quietly mark paid, an hour later, exactly what the
     * webhook path just refused.
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
     * Records a refund the gateway carried out without us asking — issued from its dashboard, or
     * forced by a cardholder dispute. Without this `refundedAmount()` reports 0 for money that has
     * already left the merchant account, and every "is this order still paid for" check built on it
     * silently answers wrong.
     *
     * $cumulativeRefunded is the gateway's own running total for this charge, so a re-delivered or
     * out-of-order callback settles to the same number instead of stacking: what gets recorded is
     * the difference from what we already know about, capped at what's still refundable. Pass null
     * when the gateway reports a full reversal without a figure. Returns null when there is nothing
     * new to record — which is the normal case for the callback that echoes our own refund() call.
     *
     * $refundExternalId is the reversal's OWN gateway id where one exists: it both dedups and is
     * stored. $reference is for gateways whose reversal callback carries no per-refund id — it is
     * only stored, never used to dedup (several partial refunds share it, so deduping on it would
     * drop the second one); there the running total is what keeps this idempotent.
     */
    protected function recordExternalRefund(
        Payment $charge,
        ?int $cumulativeRefunded,
        ?string $refundExternalId = null,
        array $raw = [],
        ?string $reference = null,
    ): ?Payment {
        if ($refundExternalId !== null && $charge->refunds()->withTrashed()->where('external_id', $refundExternalId)->exists()) {
            return null; // already recorded, by us or by an earlier delivery of this same callback
        }

        $remainder = $charge->refundableRemainder();

        $amount = $cumulativeRefunded === null
            ? $remainder
            : min($cumulativeRefunded - $charge->refundedAmount(), $remainder);

        return $this->writeRefundRow($charge, $amount, $refundExternalId ?? $reference, $raw);
    }

    /**
     * The other shape of reversal callback: one that reports THIS reversal's own amount rather than
     * the order's running total (WayForPay). There's no total to settle against, so idempotency
     * rests entirely on $dedupId — it must be stable across re-deliveries and distinct per reversal,
     * or a re-delivery is recorded twice.
     */
    protected function recordExternalReversal(Payment $charge, int $amount, string $dedupId, array $raw = []): ?Payment
    {
        if ($charge->refunds()->withTrashed()->where('external_id', $dedupId)->exists()) {
            return null; // an earlier delivery of this same reversal
        }

        return $this->writeRefundRow($charge, min($amount, $charge->refundableRemainder()), $dedupId, $raw);
    }

    /** Nothing left to refund (or nothing new) is a no-op, never a negative or over-refunding row. */
    private function writeRefundRow(Payment $charge, int $amount, ?string $externalId, array $raw): ?Payment
    {
        if ($amount <= 0) {
            return null;
        }

        return Payment::recordRefundOf($charge, new Money($amount, $charge->currency), $externalId, $raw);
    }

    /**
     * A reversal this driver recognizes but can't put a trustworthy number on — logged loudly
     * rather than swallowed, because refundedAmount() is about to disagree with the merchant
     * account. Only WayForPay needs this: its callback documents no refunded-amount field, so there
     * is nothing to record that wouldn't be a guess (see "Refunds issued outside the package" in
     * the README).
     */
    protected function reportUnrecordedReversal(Payment $charge, array $payload): void
    {
        Log::warning("Billing [{$this->gatewayName}]: a reversal was reported for a payment but not recorded — refundedAmount() will understate it", [
            'payment_id' => $charge->id,
            'payload' => $payload,
        ]);
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
        // One transaction: demote-then-upsert is two statements, and gateways do deliver two
        // webhooks for one checkout close enough together to interleave them (Stripe's
        // checkout.session.completed and payment_intent.succeeded) — which lands either two rows
        // flagged default or none.
        return DB::transaction(function () use (
            $billableType, $billableId, $tenantId, $externalCustomerId, $externalId, $last4, $brand, $expiresAt
        ) {
            $method = PaymentMethod::updateOrCreate(
                ['gateway' => $this->gatewayName, 'external_customer_id' => $externalCustomerId, 'external_id' => $externalId],
                [
                    'type' => 'card',
                    'brand' => $brand,
                    'last4' => $last4,
                    'expires_at' => $expiresAt,
                    'tenant_id' => $tenantId,
                    'billable_type' => $billableType,
                    'billable_id' => $billableId,
                ],
            );

            // Only a newly saved card takes over as default. Re-delivered webhooks are a fact of
            // life (WayForPay retries for up to four days), and promoting on every delivery means
            // an old one arriving after the customer saved a new card silently moves their default
            // back to the card they replaced.
            if (! $method->wasRecentlyCreated && ! $method->is_default) {
                return $method;
            }

            PaymentMethod::query()
                ->where('billable_type', $billableType)
                ->where('billable_id', $billableId)
                ->where('gateway', $this->gatewayName)
                ->whereKeyNot($method->getKey())
                ->update(['is_default' => false]);

            $method->forceFill(['is_default' => true])->save();

            return $method;
        });
    }
}
