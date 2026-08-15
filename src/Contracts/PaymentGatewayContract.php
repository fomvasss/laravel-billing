<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Support\Money;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * The whole "entry ticket" into the driver system — 4 required methods, everything else
 * (refunds, subscriptions, tokenization, status polling) is opt-in via the other contracts
 * in this namespace, checked with instanceof by BillingManager.
 */
interface PaymentGatewayContract
{
    public function charge(Payable $payable, Money $amount, ChargeOptions $options = new ChargeOptions()): PaymentResult;

    /**
     * $webhookCall — the already-stored, already-signature-verified record (ProcessWebhookJob runs
     * queued, long after the live Request is gone). Signature verification itself does NOT happen
     * here — it's spatie's own SignatureValidator, run synchronously pre-queue; see the driver's
     * matching SignatureValidator class, registered alongside extend() in "Webhook pipeline".
     */
    public function handleWebhook(WebhookCall $webhookCall): WebhookResult;

    /** Human-readable name for the admin UI — required even without AbstractGateway, BillingManager::gateways() calls it statically. */
    public static function label(): string;

    /** Field schema for the admin credentials form (name/type/secret/help). */
    public static function credentialFields(): array;

    /** ISO 4217 currency codes this gateway accepts. */
    public static function supportedCurrencies(): array;
}
