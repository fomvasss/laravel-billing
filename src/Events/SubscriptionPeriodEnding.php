<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A paid period is about to run out — the counterpart of TrialWillEnd for subscriptions that are
 * already paying. Fired from billing:send-period-notices at each period_ending_notices interval
 * before current_period_ends_at, at most once per notice per period.
 *
 * $willRenew says what the package intends to do when that moment comes: true = charge the card
 * again (the "we'll bill you 299 UAH on the 14th" notice), false = let the subscription end,
 * because the customer already cancelled it (the "your access ends on the 14th, come back?" one).
 * It states the package's intent, not a guarantee: a renewal it means to make can still be
 * declined by the bank, and a subscription with no saved card renews only if someone pays the
 * invoice — which is exactly when this event is worth listening to.
 */
class SubscriptionPeriodEnding
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly string|int $notice,
        public readonly bool $willRenew,
    ) {}
}
