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
        /** Dedup key for ProcessWebhookJob's unique(name, external_id) check on webhook_calls — always set except for Ignored. */
        public ?string $externalId = null,
        public array $raw = [],
    ) {}
}
