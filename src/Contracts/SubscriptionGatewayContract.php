<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;

/**
 * Optional — implement only for gateways with native subscriptions on their side (Stripe has this,
 * LiqPay/WayForPay/Monobank don't). The hard rule: createSubscription() MUST store the provider's
 * subscription reference in subscriptions.external_id — that column is the ownership marker
 * (Subscription::isProviderManaged()); every package scheduler skips non-null rows, trusting the
 * provider to renew/dun/convert and your handleWebhook() to map its callbacks to subscription
 * events. Per-subscription, not per-gateway: the same driver can serve package-managed rows too.
 */
interface SubscriptionGatewayContract
{
    /** Price, not Plan — a subscription is always pinned to one concrete interval/currency/gateway combination, never the abstract Plan. */
    public function createSubscription(Billable $billable, Price $price, array $options = []): Subscription;

    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = true): Subscription;

    /** May throw NotSupportedException if the gateway can't swap prices on an existing subscription. */
    public function swapPlan(Subscription $subscription, Price $newPrice): Subscription;
}
