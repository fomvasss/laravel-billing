<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Illuminate\Support\Number;

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
     *
     * For text a human typed into a form use parse() instead — this one trusts its input and will
     * happily read "1 299,00" as 1.00. Rounds half-up, always: a conversion has one obviously
     * right answer, unlike multiply(), where the mode is the caller's to pick.
     */
    public static function fromDecimal(string|float|int $amount, string $currency): self
    {
        return new self((int) round((float) $amount * 100), $currency);
    }

    /**
     * Strict parser for human input — an admin price field, an imported spreadsheet cell. Accepts
     * a dot or a comma as the decimal separator and spaces (including the non-breaking ones a
     * browser pastes) or the other separator as thousands: "1299", "1299.00", "1 299,00",
     * "1.299,00", "1,299.00" all give 129900.
     *
     * Everything else throws instead of guessing, because every guess here is a silently wrong
     * price: more than two decimals, stray characters, and — deliberately — a lone separator
     * followed by exactly three digits ("1,299"), which is 1299 to an American and 1.299 to a
     * Ukrainian with no way to tell from the string.
     */
    public static function parse(string $amount, string $currency): self
    {
        // \s misses U+00A0/U+202F, and those are exactly what a copy-paste from a browser or a
        // spreadsheet brings along as the thousands separator.
        $normalized = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $amount);

        if ($normalized === '' || ! preg_match('/^\d+(?:[.,]\d+)*$/', $normalized)) {
            throw new \InvalidArgumentException("Money::parse() cannot read \"{$amount}\" as an amount.");
        }

        $dots = substr_count($normalized, '.');
        $commas = substr_count($normalized, ',');

        if ($dots === 0 && $commas === 0) {
            return new self((int) $normalized * 100, $currency);
        }

        // Both kinds present: the rightmost one is the decimal separator, whatever the locale, and
        // the other can only be grouping ("1.299,00" — uk/de, "1,299.00" — en).
        if ($dots > 0 && $commas > 0) {
            $decimalSeparator = strrpos($normalized, '.') > strrpos($normalized, ',') ? '.' : ',';
            $groupSeparator = $decimalSeparator === '.' ? ',' : '.';

            [$integer, $fraction] = explode($decimalSeparator, $normalized, 2);

            if (substr_count($fraction, $decimalSeparator) > 0 || ! self::isGrouped($integer, $groupSeparator)) {
                throw new \InvalidArgumentException("Money::parse() cannot read \"{$amount}\" as an amount.");
            }

            return self::fromParts(str_replace($groupSeparator, '', $integer), $fraction, $amount, $currency);
        }

        $separator = $dots > 0 ? '.' : ',';
        $parts = explode($separator, $normalized);
        $last = end($parts);

        // Grouping only ("1,234,567") — never a fraction, so there is nothing to be ambiguous about.
        if (count($parts) > 2) {
            if (! self::isGrouped($normalized, $separator)) {
                throw new \InvalidArgumentException("Money::parse() cannot read \"{$amount}\" as an amount.");
            }

            return new self((int) str_replace($separator, '', $normalized) * 100, $currency);
        }

        if (strlen($last) === 3) {
            throw new \InvalidArgumentException(
                "Money::parse() cannot tell whether \"{$amount}\" means thousands or a fraction. "
                .'Write the fraction with two digits ("1 299,00") or drop the separator ("1299").'
            );
        }

        return self::fromParts($parts[0], $last, $amount, $currency);
    }

    /** Back to the decimal string your own columns/invoices use — "19.99", never a float. */
    public function toDecimal(): string
    {
        return number_format($this->amount / 100, 2, '.', '');
    }

    /**
     * Human-facing output ("1 299,00 ₴") — for a receipt, an email, an admin table. Falls back to
     * "1299.00 UAH" without ext-intl, which the package doesn't require; pass $locale to override
     * the app's own.
     */
    public function format(?string $locale = null): string
    {
        if (! extension_loaded('intl')) {
            return $this->toDecimal().' '.$this->currency;
        }

        return Number::currency((float) $this->toDecimal(), $this->currency, $locale);
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->isSameCurrency($other);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other, 'add');

        return new self($this->amount + $other->amount, $this->currency);
    }

    /** Throws (via the constructor) when the result would go below zero — money never does. */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other, 'subtract');

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Rounds to the minor unit — 3 × 19.99 is 59.97, not 59.969999…. The mode is a parameter and
     * not a hidden constant because half-up and half-even are different money: over a long invoice
     * half-up drifts in the merchant's favour, which is exactly why some accounting rules mandate
     * half-even. Half-up stays the default — it's what an invoice line is normally expected to do.
     *
     * $factor is a float, so a factor that itself can't be represented exactly (0.1, 1.005) is
     * approximate before rounding ever happens. That's the ceiling of this class: if a project
     * needs exact decimal factors — tax rates, proration to the day — that's the point to reach
     * for brick/money rather than to grow this one.
     */
    public function multiply(int|float $factor, Rounding $rounding = Rounding::HalfUp): self
    {
        return new self($rounding->apply($this->amount * $factor), $this->currency);
    }

    /**
     * Splits the amount by ratios without losing or inventing a single minor unit — the reason a
     * naive multiply() per share is wrong: 100.00 split in three is 33.34 + 33.33 + 33.33, and it
     * has to add back up to exactly 100.00. Use it for a discount spread over basket lines, VAT per
     * item, or a revenue split.
     *
     * The distribution policy is floor-then-leftovers-to-the-earliest-shares (Fowler's), and that
     * is a choice, not the only right answer: the alternatives are giving the leftovers to the
     * largest fractional remainders (Hamilton's, fairer per share but order-dependent in ties) and
     * returning the remainder separately instead of distributing it at all. Order your ratios so
     * that "earliest" means what you want it to mean; if a project ever needs one of the other two,
     * brick/money implements all of them and that is the moment to take the dependency.
     *
     * @param  array<int|float>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        $ratios = array_values($ratios);
        $total = 0.0;

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new \InvalidArgumentException("Money::allocate() got a negative ratio ({$ratio}).");
            }

            $total += $ratio;
        }

        if ($total <= 0) {
            throw new \InvalidArgumentException('Money::allocate() needs at least one ratio greater than zero.');
        }

        $shares = [];
        $distributed = 0;

        foreach ($ratios as $ratio) {
            $share = (int) floor($this->amount * $ratio / $total);
            $shares[] = $share;
            $distributed += $share;
        }

        for ($i = 0, $remainder = $this->amount - $distributed; $remainder > 0; $i++, $remainder--) {
            $shares[$i % count($shares)]++;
        }

        return array_map(fn (int $share): self => new self($share, $this->currency), $shares);
    }

    /** Every group after the first is exactly three digits — otherwise it isn't grouping at all. */
    private static function isGrouped(string $value, string $separator): bool
    {
        $groups = explode($separator, $value);

        if (count($groups) === 1) {
            return true;
        }

        foreach (array_slice($groups, 1) as $group) {
            if (strlen($group) !== 3) {
                return false;
            }
        }

        return true;
    }

    private static function fromParts(string $integer, string $fraction, string $original, string $currency): self
    {
        if (strlen($fraction) > 2) {
            throw new \InvalidArgumentException(
                "Money::parse() got more than two decimals in \"{$original}\" — {$currency} has no unit smaller than 1/100."
            );
        }

        // Composed from the digits themselves, so unlike fromDecimal() there is no float in the
        // path at all: "19.99" is 19 * 100 + 99, exactly.
        return new self((int) $integer * 100 + (int) str_pad($fraction, 2, '0'), $currency);
    }

    private function assertSameCurrency(self $other, string $operation): void
    {
        if (! $this->isSameCurrency($other)) {
            throw new \InvalidArgumentException(
                "Cannot {$operation} {$other->currency} and {$this->currency} — convert one of them first (see CurrencyConverterContract)."
            );
        }
    }
}
