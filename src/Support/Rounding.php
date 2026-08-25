<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

/**
 * How Money::multiply() lands on a whole minor unit when the result falls between two.
 *
 * Deliberately in Support, not Enums: nothing persists this, it's part of the Money API surface.
 * PHP 8.4 ships a native RoundingMode enum with the same idea — this exists because the package
 * supports 8.3.
 */
enum Rounding: string
{
    /** 0.5 goes up (2.5 → 3, 3.5 → 4). The default, and what everyone means by "rounding". */
    case HalfUp = 'half_up';

    /** 0.5 goes down (2.5 → 2, 3.5 → 3). */
    case HalfDown = 'half_down';

    /**
     * 0.5 goes to the nearest even (2.5 → 2, 3.5 → 4) — "banker's rounding". Over many lines it
     * doesn't drift upwards the way HalfUp does, which is why some accounting rules require it.
     */
    case HalfEven = 'half_even';

    /** Always up, however small the fraction (2.01 → 3) — the merchant never loses the kopiyka. */
    case Up = 'up';

    /** Always down (2.99 → 2) — the customer never overpays it. */
    case Down = 'down';

    /** Amounts are never negative here, so "up"/"down" need no away-from-zero caveat. */
    public function apply(float $value): int
    {
        return (int) match ($this) {
            self::HalfUp => round($value, 0, PHP_ROUND_HALF_UP),
            self::HalfDown => round($value, 0, PHP_ROUND_HALF_DOWN),
            self::HalfEven => round($value, 0, PHP_ROUND_HALF_EVEN),
            self::Up => ceil($value),
            self::Down => floor($value),
        };
    }
}
