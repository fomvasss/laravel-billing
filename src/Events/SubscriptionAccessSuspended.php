<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires once, the moment a renewal first fails on a subscription whose grace_access resolves to
 * false (config('billing.grace_access') or the Price override) — isActive() just turned false
 * while dunning retries keep running in the background. Distinct from SubscriptionPaymentFailed,
 * which fires on every retry regardless of the access policy: this one only fires when access
 * actually changed. Not fired again on subsequent retries in the same past_due episode (access is
 * already off), and not fired at all when grace_access stays true — there access fades with
 * grace_ends_at, which was never an explicit "cut" moment either.
 */
class SubscriptionAccessSuspended
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Subscription $subscription) {}
}
