<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Fixtures;

use Fomvasss\Billing\Contracts\ManagesProviderSubscriptions;
use Fomvasss\Billing\Contracts\StartsProviderSubscriptions;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\SubscriptionSnapshot;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Gateways\AbstractGateway;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Carbon;

/**
 * A provider that runs subscriptions on its own side, for testing the core without a real one.
 * Its webhook payload is the snapshot itself: `{event_id, occurred_at, subscription_id (ours, until
 * linked), provider_id, status, period_ends_at, cancels_at, pause_ends_at, price_id}`. The
 * management calls answer with the state a real provider would report back.
 */
class FakeProviderGateway extends AbstractGateway implements StartsProviderSubscriptions, ManagesProviderSubscriptions
{
    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        return new PaymentResult(url: "https://provider.test/pay/{$payment->id}", externalId: "txn_{$payment->id}");
    }

    public function startSubscription(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        return new PaymentResult(url: "https://provider.test/subscribe/{$payment->id}", externalId: "txn_{$payment->id}");
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $event = $webhookCall->payload;

        $subscription = $this->findProviderSubscription($event['provider_id'] ?? null)
            ?? $this->findSubscriptionByReference($event['subscription_id'] ?? null);

        if ($subscription === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        return new WebhookResult(
            type: WebhookEventType::Subscription,
            status: 'synced',
            subscription: $subscription,
            externalId: $event['event_id'],
            raw: $event,
            snapshot: new SubscriptionSnapshot(
                externalId: $event['provider_id'],
                status: SubscriptionStatus::from($event['status']),
                currentPeriodEndsAt: self::date($event['period_ends_at'] ?? null),
                cancelsAt: self::date($event['cancels_at'] ?? null),
                pauseEndsAt: self::date($event['pause_ends_at'] ?? null),
                priceId: $event['price_id'] ?? null,
                occurredAt: self::date($event['occurred_at'] ?? null),
            ),
        );
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd): SubscriptionSnapshot
    {
        return $this->snapshotOf($subscription,
            status: $atPeriodEnd ? $subscription->status : SubscriptionStatus::Canceled,
            cancelsAt: $atPeriodEnd ? $subscription->current_period_ends_at : now(),
        );
    }

    public function pause(Subscription $subscription, ?\DateTimeInterface $until): SubscriptionSnapshot
    {
        return $this->snapshotOf($subscription, status: SubscriptionStatus::Paused, pauseEndsAt: $until);
    }

    public function resume(Subscription $subscription): SubscriptionSnapshot
    {
        return $this->snapshotOf($subscription, status: SubscriptionStatus::Active);
    }

    public function swapPrice(Subscription $subscription, Price $price): SubscriptionSnapshot
    {
        return $this->snapshotOf($subscription, priceId: $price->id);
    }

    public static function credentialFields(): array
    {
        return [];
    }

    public static function supportedCurrencies(): array
    {
        return ['USD'];
    }

    private function snapshotOf(
        Subscription $subscription,
        ?SubscriptionStatus $status = null,
        ?\DateTimeInterface $cancelsAt = null,
        ?\DateTimeInterface $pauseEndsAt = null,
        ?string $priceId = null,
    ): SubscriptionSnapshot {
        return new SubscriptionSnapshot(
            externalId: $subscription->external_id,
            status: $status ?? $subscription->status,
            currentPeriodEndsAt: $subscription->current_period_ends_at,
            cancelsAt: $cancelsAt ?? $subscription->cancels_at,
            pauseEndsAt: $pauseEndsAt,
            priceId: $priceId,
        );
    }

    private static function date(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }
}
