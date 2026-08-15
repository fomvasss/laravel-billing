<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Enums;

enum SubscriptionStatus: string
{
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
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::PastDue => 'Past due',
            self::Canceled => 'Canceled',
            self::Ended => 'Ended',
        };
    }
}
