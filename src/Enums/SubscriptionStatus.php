<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Enums;

enum SubscriptionStatus: string
{
    /**
     * Created at checkout, waiting for the first payment — no access, and none of the schedulers
     * touch it. A subscription row has to exist before the payment can point at it, and without
     * a status of its own the only way to say "not paid for yet" was a `trialing` row with an
     * already-past trial_ends_at — which billing:expire-trials cannot tell from a lapsed trial
     * and duly moves to `ended`, so the payment that arrives minutes later is refused by
     * recordRenewalSuccess() and the customer pays for nothing.
     */
    case Incomplete = 'incomplete';
    case Trialing = 'trialing';
    case Active = 'active';
    /** Local, not gateway-driven — consumer skips a cycle without cancelling (Subscription::pause()/resume()). */
    case Paused = 'paused';
    /** Failed recurring charge, still within grace_ends_at/recurring_attempts (dunning). */
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    /** Trial expired without converting to a paid subscription (ExpireTrialsJob). */
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Incomplete => 'Incomplete',
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::PastDue => 'Past due',
            self::Canceled => 'Canceled',
            self::Ended => 'Ended',
        };
    }
}
