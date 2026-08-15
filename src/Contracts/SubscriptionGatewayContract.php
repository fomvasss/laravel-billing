<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Subscription;

/** Optional — implement only for gateways with native subscriptions on their side (Stripe has this, LiqPay/WayForPay/Monobank don't). */
interface SubscriptionGatewayContract
{
    public function createSubscription(Billable $billable, Plan $plan, array $options = []): Subscription;

    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = true): Subscription;

    /** May throw NotSupportedException if the gateway can't swap plans on an existing subscription. */
    public function swapPlan(Subscription $subscription, Plan $newPlan): Subscription;
}
