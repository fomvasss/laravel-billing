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
    ) {
        // The column is `unsignedBigInteger`, which Postgres implements as a plain signed bigint —
        // so this is the only place a negative amount is actually stopped. A refund is its own row
        // with a positive amount, never a negative charge.
        if ($amount < 0) {
            throw new \InvalidArgumentException("Money amount cannot be negative, got {$amount} {$currency}.");
        }
    }

    /**
     * Bridge from however your own app stores prices — a `decimal:2` cast (a string, in Eloquent),
     * a float, or a plain number. round() before the int cast is the whole point: `(int) (19.99 *
     * 100)` is 1998, not 1999, because 19.99 has no exact binary representation. Losing a kopiyka
     * per line item is the classic version of this bug.
     */
    public static function fromDecimal(string|float|int $amount, string $currency): self
    {
        return new self((int) round((float) $amount * 100), $currency);
    }

    /** Back to the decimal string your own columns/invoices use — "19.99", never a float. */
    public function toDecimal(): string
    {
        return number_format($this->amount / 100, 2, '.', '');
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }
}
