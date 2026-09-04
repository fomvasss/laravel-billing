<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Enums;

/**
 * Who started the charge — a person acting now, or the system on its own.
 *
 * Deliberately about the initiator, not the mechanism: "paid from a saved card" and "charged
 * automatically" are not the same statement, and a customer asking why money left their account
 * is asking the first question. A one-click charge with a stored card is `Manual`; only a charge
 * nobody was present for (a scheduled renewal, a dunning retry) is `Automatic`.
 */
enum PaymentInitiation: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Automatic => 'Automatic',
        };
    }
}
