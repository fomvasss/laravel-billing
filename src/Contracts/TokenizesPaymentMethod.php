<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Model;

/** Optional — saved card / recurring charges. */
interface TokenizesPaymentMethod
{
    /**
     * $billable is always an Eloquent model in practice (the morphTo target of PaymentMethod's
     * billable_type/billable_id) — Model&Billable, not the bare marker interface, so drivers can
     * read $billable->getKey()/$billable::class without an instanceof check.
     *
     * Returns external_customer_id.
     */
    public function createCustomer(Model&Billable $billable): string;

    /** $token — whatever came from the gateway's JS SDK on the frontend (e.g. Stripe PaymentMethod id, LiqPay card token). */
    public function attachPaymentMethod(Model&Billable $billable, array $token): PaymentMethod;

    /**
     * One-off/recurring charge with a saved method, without a redirect. $payment — same reasoning
     * as PaymentGatewayContract::charge(): the driver needs the row's id for the merchant
     * reference, not just an amount. Same ChargeOptions as charge() — receiptItems included, so an
     * off-session charge (renewal, overage, top-up) can be fiscalized exactly like a checkout one;
     * a gateway with nothing to put a basket in (Stripe's PaymentIntents API has no such concept)
     * simply ignores it.
     */
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, ChargeOptions $options = new ChargeOptions()): PaymentResult;

    public function detachPaymentMethod(PaymentMethod $method): void;
}
