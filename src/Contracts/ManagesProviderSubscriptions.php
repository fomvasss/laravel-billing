<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\SubscriptionSnapshot;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;

/**
 * Optional — for gateways that run subscriptions on their own side. Subscription::cancel()/
 * pause()/resume()/swapPlan() on a provider-managed row (external_id set) land here instead of
 * changing the row locally. Each method makes the provider call and returns the state the provider
 * reports back; the model applies it (applyProviderSnapshot()), so events come from the one place.
 * Throw NotSupportedException for an operation the provider doesn't have.
 */
interface ManagesProviderSubscriptions
{
    /** $atPeriodEnd false = right away; true = access runs to the end of the paid period. */
    public function cancel(Subscription $subscription, bool $atPeriodEnd): SubscriptionSnapshot;

    /** $until null = until resumed. */
    public function pause(Subscription $subscription, ?\DateTimeInterface $until): SubscriptionSnapshot;

    public function resume(Subscription $subscription): SubscriptionSnapshot;

    /** The provider's own proration rules apply; the snapshot should carry $price->id as priceId. */
    public function swapPrice(Subscription $subscription, Price $price): SubscriptionSnapshot;
}
