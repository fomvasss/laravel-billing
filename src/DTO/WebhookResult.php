<?php

declare(strict_types=1);

namespace Fomvasss\Billing\DTO;

use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Subscription;

final readonly class WebhookResult
{
    public function __construct(
        public WebhookEventType $type,
        /**
         * Value within $type — the exact vocabulary ProcessWebhookJob matches on to dispatch a core
         * event:
         *  - Payment: 'succeeded' | 'failed' | 'refunded' | 'canceled'
         *  - Subscription: 'synced' (writes $snapshot onto $subscription — Subscription::applyProviderSnapshot()
         *    — and fires whatever events the change calls for); or the older event-only statuses
         *    'created' | 'renewed' | 'payment_failed' | 'canceled' | 'trial_will_end', which write nothing
         *  - PaymentMethod: 'attached' | 'detached'
         *  - Ignored: unused
         */
        public string $status,
        public ?Payment $payment = null,
        public ?Subscription $subscription = null,
        public ?PaymentMethod $paymentMethod = null,
        /**
         * The gateway-side reference this event is about — combined into dedupKey(), always set
         * except for Ignored. For a Subscription result it must identify the EVENT (Paddle's evt_,
         * Stripe's evt_), not the subscription: one subscription reports 'synced' many times, and a
         * sub_ id here would let only the first of them through.
         */
        public ?string $externalId = null,
        public array $raw = [],
        /** The provider's state to apply, for a 'synced' Subscription result. */
        public ?SubscriptionSnapshot $snapshot = null,
    ) {}

    /**
     * Dedup identity of this delivery, claimed against unique(name, external_id) on
     * billing_webhook_calls. type+status are part of the key on purpose: a DIFFERENT outcome for
     * the same gateway reference (declined, then the customer retries the same checkout and pays —
     * Stripe reuses the PaymentIntent, WayForPay/Hutko the order reference) must still dispatch,
     * while a re-delivery of the SAME outcome must not.
     */
    public function dedupKey(): ?string
    {
        return $this->externalId === null
            ? null
            : $this->type->name . ':' . $this->status . ':' . $this->externalId;
    }
}
