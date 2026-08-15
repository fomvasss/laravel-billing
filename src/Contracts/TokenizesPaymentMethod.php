<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Support\Money;

/** Optional — saved card / recurring charges. */
interface TokenizesPaymentMethod
{
    /** Returns external_customer_id. */
    public function createCustomer(Billable $billable): string;

    /** $token — whatever came from the gateway's JS SDK on the frontend (e.g. Stripe PaymentMethod id, LiqPay card token). */
    public function attachPaymentMethod(Billable $billable, array $token): PaymentMethod;

    /** One-off/recurring charge with a saved method, without a redirect. */
    public function chargePaymentMethod(PaymentMethod $method, Money $amount, array $options = []): PaymentResult;

    public function detachPaymentMethod(PaymentMethod $method): void;
}
