<?php

declare(strict_types=1);

namespace Fomvasss\Billing\DTO;

final readonly class PaymentResult
{
    public function __construct(
        /** Redirect-style checkout (WayForPay/Monobank/Stripe Checkout). */
        public ?string $url = null,
        /** Auto-submit form: ['action' => ..., 'fields' => [...]] (LiqPay cnb_form). */
        public ?array $form = null,
        /** TTL of this specific link/form — computed by the driver itself, not the core. */
        public ?\DateTimeInterface $expiresAt = null,
        public ?string $externalId = null,
        /** Raw gateway response — refund()/chargePaymentMethod() callers that need more than externalId. */
        public array $raw = [],
        /**
         * refund() only: the gateway accepted the refund but has yet to approve it (Paddle). The row
         * is recorded as pending and PaymentRefunded waits for the driver's approval webhook.
         */
        public bool $pending = false,
    ) {}
}
