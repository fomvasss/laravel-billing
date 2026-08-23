<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Events\PaymentCanceled;
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
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Database\UniqueConstraintViolationException;

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
                'canceled' => PaymentCanceled::dispatch($result->payment),
                default => null,
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

    /**
     * The polling-path twin of ProcessWebhookJob's claim: reconciliation has no webhook-call row
     * of its own to stamp, so it claims the dedup key by INSERTing a synthetic one. The same
     * unique(name, external_id) index then arbitrates between a late real webhook and this poll —
     * whichever lands second is silently dropped instead of double-dispatching (double period
     * advance, double order fulfillment). Billing::refund() uses it the same way, so the callback
     * echoing a refund we issued ourselves is dropped too. $source is what the synthetic row's url
     * column records, for anyone reading the table.
     */
    public static function dispatchOnce(string $gateway, WebhookResult $result, string $source = 'reconcile'): void
    {
        $key = $result->dedupKey();

        if ($key !== null) {
            try {
                BillingWebhookCall::create([
                    'name' => $gateway,
                    'url' => $source,
                    'external_id' => $key,
                    'payload' => $result->raw,
                ]);
            } catch (UniqueConstraintViolationException) {
                return; // the real webhook already claimed this outcome — see docblock
            }
        }

        self::dispatch($result);
    }
}
