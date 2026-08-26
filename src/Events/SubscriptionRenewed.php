<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A paid period was granted. The status the subscription held right before it says which of the
 * three transitions this is — the wording of a "welcome", a "renewed" and a "we got you back"
 * notice differ, and by dispatch time the subscription itself is `active` in all three:
 *
 * - Trialing — a trial just converted, this is the customer's first paid period
 * - PastDue — a renewal recovered after a failed attempt, access is back
 * - Active — an ordinary renewal
 *
 * Null when the transition isn't known: a provider-managed subscription renewed on the gateway's
 * side and the package only learns about it from the webhook.
 */
class SubscriptionRenewed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly ?SubscriptionStatus $previousStatus = null,
    ) {}
}
