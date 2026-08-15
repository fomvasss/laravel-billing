<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Enums;

enum WebhookEventType
{
    /** Payment status changed (paid/failed/canceled/refunded). */
    case Payment;

    /** A card/token was attached or detached — not tied to a specific Payment. */
    case PaymentMethod;

    /** The gateway's own subscription state changed (renewed/canceled/past_due) — native-subscription gateways only (Stripe). */
    case Subscription;

    /** A valid webhook whose event we don't care about (intermediate gateway status etc.) — not an error, just nothing to do. */
    case Ignored;
}
