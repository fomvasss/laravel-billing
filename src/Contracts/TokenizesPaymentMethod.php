<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;

/** Optional — saved card / recurring charges. */
interface TokenizesPaymentMethod
{
    /** Returns external_customer_id. */
    public function createCustomer(Billable $billable): string;

    /** $token — whatever came from the gateway's JS SDK on the frontend (e.g. Stripe PaymentMethod id, LiqPay card token). */
    public function attachPaymentMethod(Billable $billable, array $token): PaymentMethod;

    /**
     * One-off/recurring charge with a saved method, without a redirect. $payment — same reasoning
     * as PaymentGatewayContract::charge(): the driver needs the row's id for the merchant
     * reference, not just an amount.
     */
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, array $options = []): PaymentResult;

    public function detachPaymentMethod(PaymentMethod $method): void;
}
