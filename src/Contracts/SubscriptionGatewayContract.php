<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;

/** Optional — implement only for gateways with native subscriptions on their side (Stripe has this, LiqPay/WayForPay/Monobank don't). */
interface SubscriptionGatewayContract
{
    /** Price, not Plan — a subscription is always pinned to one concrete interval/currency/gateway combination, never the abstract Plan. */
    public function createSubscription(Billable $billable, Price $price, array $options = []): Subscription;

    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = true): Subscription;

    /** May throw NotSupportedException if the gateway can't swap prices on an existing subscription. */
    public function swapPlan(Subscription $subscription, Price $newPrice): Subscription;
}
