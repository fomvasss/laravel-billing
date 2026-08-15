<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

/**
 * Money is always an integer in the currency's minor units (kopecks/cents), never a float —
 * see "Money" in the package plan for why (Monobank/Stripe/most PSPs work the same way).
 */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {}

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }
}
