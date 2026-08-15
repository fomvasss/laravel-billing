<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Enums;

enum PricingType: string
{
    /** Fixed amount, qty/current_usage ignored. */
    case Flat = 'flat';

    /** amount * subscriptions.qty (seats/licenses, fixed at signup/change). */
    case Licensed = 'licensed';

    /** amount * subscriptions.current_usage (pay-as-you-go, grows with actual consumption). */
    case Metered = 'metered';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'Flat',
            self::Licensed => 'Licensed',
            self::Metered => 'Metered',
        };
    }
}
