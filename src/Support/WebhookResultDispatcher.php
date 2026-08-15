<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentMethodAttached;
use Fomvasss\Billing\Events\PaymentMethodDetached;
use Fomvasss\Billing\Events\PaymentRefunded;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Events\SubscriptionCreated;
use Fomvasss\Billing\Events\SubscriptionPaymentFailed;
use Fomvasss\Billing\Events\SubscriptionRenewed;
use Fomvasss\Billing\Events\TrialWillEnd;

/**
 * Shared by ProcessWebhookJob (webhook path) and ReconcilePendingPaymentsCommand (status-polling
 * fallback path) — both turn a WebhookResult into the same core events, so the mapping lives once.
 */
final class WebhookResultDispatcher
{
    public static function dispatch(WebhookResult $result): void
    {
        match ($result->type) {
            WebhookEventType::Payment => match ($result->status) {
                'succeeded' => PaymentSucceeded::dispatch($result->payment),
                'failed' => PaymentFailed::dispatch($result->payment),
                'refunded' => PaymentRefunded::dispatch($result->payment),
                default => null, // 'canceled' and anything else — nothing to notify
            },
            WebhookEventType::Subscription => match ($result->status) {
                'created' => SubscriptionCreated::dispatch($result->subscription),
                'renewed' => SubscriptionRenewed::dispatch($result->subscription),
                'payment_failed' => SubscriptionPaymentFailed::dispatch($result->subscription),
                'canceled' => SubscriptionCancelled::dispatch($result->subscription),
                'trial_will_end' => TrialWillEnd::dispatch($result->subscription),
                default => null,
            },
            WebhookEventType::PaymentMethod => match ($result->status) {
                'attached' => PaymentMethodAttached::dispatch($result->paymentMethod),
                'detached' => PaymentMethodDetached::dispatch($result->paymentMethod),
                default => null,
            },
            WebhookEventType::Ignored => null,
        };
    }
}
