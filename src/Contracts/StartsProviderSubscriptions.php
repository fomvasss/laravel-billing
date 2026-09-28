<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\Models\Payment;

/**
 * Optional — the start of a provider-managed subscription, called through Billing::startSubscription().
 * The consumer creates the Subscription (status `incomplete`, the gateway set) and the first Payment
 * with that Subscription as its payable; the driver opens a checkout that makes the provider create
 * its own subscription for the payment's subscription price.
 *
 * Checkout is asynchronous on most providers, so the provider's subscription id usually isn't known
 * yet: the row is linked later, when the driver's webhook hands Subscription::applyProviderSnapshot()
 * a snapshot carrying it (that snapshot is also what makes the row provider-managed). A provider
 * that creates the subscription on the spot may link it before returning.
 */
interface StartsProviderSubscriptions
{
    public function startSubscription(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult;
}
