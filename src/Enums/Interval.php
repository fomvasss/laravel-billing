<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Enums;

/** Nullable on Price — null means a one-off/lifetime price with no recurring cycle. */
enum Interval: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Minute => 'Minute',
            self::Hour => 'Hour',
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
            self::Year => 'Year',
        };
    }
}
