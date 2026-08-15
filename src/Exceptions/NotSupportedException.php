<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a driver doesn't implement an optional capability (RefundsPayments,
 * SubscriptionGatewayContract, TokenizesPaymentMethod, ChecksPaymentStatus) — a clear message
 * instead of "Call to undefined method".
 */
class NotSupportedException extends RuntimeException
{
    public static function forCapability(string $gateway, string $capability): self
    {
        return new self("Billing gateway \"{$gateway}\" does not support \"{$capability}\".");
    }
}
