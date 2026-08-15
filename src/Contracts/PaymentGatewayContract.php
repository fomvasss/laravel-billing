<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * The whole "entry ticket" into the driver system — 4 required methods, everything else
 * (refunds, subscriptions, tokenization, status polling) is opt-in via the other contracts
 * in this namespace, checked with instanceof by BillingManager.
 */
interface PaymentGatewayContract
{
    /**
     * $payment — the already-created, status=pending row (the caller creates it before
     * BillingManager::charge()), not a bare Payable+Money pair: the driver needs $payment->id as
     * the merchant reference it hands the gateway, so the later webhook can look the row back up.
     * $payment->payable/billable/amount/currency_code cover what charge() itself needs.
     */
    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult;

    /**
     * $webhookCall — the already-stored, already-signature-verified record (ProcessWebhookJob runs
     * queued, long after the live Request is gone). Signature verification itself does NOT happen
     * here — it's the driver's own Contracts\SignatureValidator, run synchronously in
     * WebhookController before the call is even stored; see "Webhook pipeline".
     */
    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult;

    /** Human-readable name for the admin UI — required even without AbstractGateway, BillingManager::gateways() calls it statically. */
    public static function label(): string;

    /** Field schema for the admin credentials form (name/type/secret/help). */
    public static function credentialFields(): array;

    /** ISO 4217 currency codes this gateway accepts. */
    public static function supportedCurrencies(): array;
}
