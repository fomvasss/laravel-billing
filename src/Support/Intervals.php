<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Carbon\CarbonInterval;

/**
 * The single parser for every cadence the package takes from config: trial-ending notices and
 * dunning retries share one entry shape — a CarbonInterval string ('3 days', '6 hours') or an int
 * meaning minutes — and one parser is what keeps that promise honest as more of them appear.
 */
final class Intervals
{
    /**
     * $context only shapes the exception message ("Unparsable retry interval \"6 hourz\"."), so a
     * misconfigured value says which config key to go fix.
     *
     * Non-positive intervals are rejected rather than normalised: as a retry pace, "0 minutes"
     * means the scheduler re-picks the subscription every run and burns every attempt within a
     * minute — the exact thing next_retry_at exists to prevent.
     */
    public static function parse(string|int $value, string $context = 'interval'): CarbonInterval
    {
        $interval = is_int($value)
            ? CarbonInterval::minutes($value)
            : CarbonInterval::make($value) ?? throw new \InvalidArgumentException("Unparsable {$context} \"{$value}\".");

        if ($interval->totalSeconds <= 0) {
            throw new \InvalidArgumentException("A {$context} must be greater than zero, got \"{$value}\".");
        }

        return $interval;
    }
}
