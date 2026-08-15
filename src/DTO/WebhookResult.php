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
         *  - Payment: 'succeeded' | 'failed' | 'refunded' | 'canceled' (canceled dispatches nothing)
         *  - Subscription: 'created' | 'renewed' | 'payment_failed' | 'canceled' | 'trial_will_end'
         *  - PaymentMethod: 'attached' | 'detached'
         *  - Ignored: unused
         */
        public string $status,
        public ?Payment $payment = null,
        public ?Subscription $subscription = null,
        public ?PaymentMethod $paymentMethod = null,
        /** The gateway-side reference this event is about — combined into dedupKey(), always set except for Ignored. */
        public ?string $externalId = null,
        public array $raw = [],
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
