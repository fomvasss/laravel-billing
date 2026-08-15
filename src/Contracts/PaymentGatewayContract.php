<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Support\Money;
use Illuminate\Http\Request;

/**
 * The whole "entry ticket" into the driver system — 4 required methods, everything else
 * (refunds, subscriptions, tokenization, status polling) is opt-in via the other contracts
 * in this namespace, checked with instanceof by BillingManager.
 */
interface PaymentGatewayContract
{
    public function charge(Payable $payable, Money $amount, ChargeOptions $options = new ChargeOptions()): PaymentResult;

    public function handleWebhook(Request $request): WebhookResult;

    /** Human-readable name for the admin UI — required even without AbstractGateway, BillingManager::gateways() calls it statically. */
    public static function label(): string;

    /** Field schema for the admin credentials form (name/type/secret/help). */
    public static function credentialFields(): array;

    /** ISO 4217 currency codes this gateway accepts. */
    public static function supportedCurrencies(): array;
}
