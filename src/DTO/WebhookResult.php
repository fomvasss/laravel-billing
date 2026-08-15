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
        /** Value within $type: 'succeeded'/'failed'/'canceled' for Payment, 'attached'/'detached' for PaymentMethod, ... */
        public string $status,
        public ?Payment $payment = null,
        public ?Subscription $subscription = null,
        public ?PaymentMethod $paymentMethod = null,
        public array $raw = [],
    ) {}
}
