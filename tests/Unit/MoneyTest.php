<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Unit;

use Fomvasss\Billing\Support\Money;
use Fomvasss\Billing\Support\Rounding;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_is_same_currency(): void
    {
        $this->assertTrue((new Money(100, 'UAH'))->isSameCurrency(new Money(999, 'UAH')));
        $this->assertFalse((new Money(100, 'UAH'))->isSameCurrency(new Money(100, 'USD')));
    }

    /** The float-representation trap: a naive (int) cast turns 19.99 into 1998, not 1999. */
    public function test_from_decimal_rounds_instead_of_truncating(): void
    {
        $this->assertSame(1999, Money::fromDecimal(19.99, 'USD')->amount);
        $this->assertSame(1999, Money::fromDecimal('19.99', 'USD')->amount); // Eloquent decimal: casts give strings
        $this->assertSame(10000, Money::fromDecimal(100, 'UAH')->amount);
        $this->assertSame(1, Money::fromDecimal('0.01', 'UAH')->amount);
        $this->assertSame(0, Money::fromDecimal('0.001', 'UAH')->amount); // below the minor unit — rounds away
    }

    public function test_to_decimal_returns_a_string_never_a_float(): void
    {
        $this->assertSame('19.99', (new Money(1999, 'USD'))->toDecimal());
        $this->assertSame('100.00', (new Money(10000, 'UAH'))->toDecimal());
        $this->assertSame('0.01', (new Money(1, 'UAH'))->toDecimal());
    }

    public function test_decimal_round_trip_is_stable(): void
    {
        foreach (['0.01', '19.99', '100.00', '1234.56'] as $decimal) {
            $this->assertSame($decimal, Money::fromDecimal($decimal, 'UAH')->toDecimal());
        }
    }

    public function test_parse_reads_every_separator_convention_a_human_types(): void
    {
        $cases = [
            '1299' => 129900,
            '1299.00' => 129900,
            '1299,00' => 129900,
            '1 299,00' => 129900,      // plain space
            "1\u{00A0}299,00" => 129900, // non-breaking space, what a browser pastes
            "1\u{202F}299,00" => 129900, // narrow non-breaking space
            '1.299,00' => 129900,      // uk/de grouping
            '1,299.00' => 129900,      // en grouping
            '1,234,567.89' => 123456789,
            '1.234.567' => 123456700,
            '0,01' => 1,
            '19.9' => 1990,
            '007.50' => 750,
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Money::parse((string) $input, 'UAH')->amount, "parsing \"{$input}\"");
        }
    }

    /** The whole point of parse() over fromDecimal(): (float) "1 299,00" is 1.0, silently. */
    public function test_parse_beats_a_naive_cast_on_a_grouped_amount(): void
    {
        $this->assertSame(129900, Money::parse('1 299,00', 'UAH')->amount);
        $this->assertSame(100, Money::fromDecimal('1 299,00', 'UAH')->amount);
    }

    public function test_parse_refuses_input_it_would_have_to_guess_at(): void
    {
        foreach (['1,299', '1.299'] as $ambiguous) {
            try {
                Money::parse($ambiguous, 'UAH');
                $this->fail("Expected \"{$ambiguous}\" to be rejected as ambiguous.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('thousands or a fraction', $e->getMessage());
            }
        }

        foreach (['', 'abc', '19.99 UAH', '-5.00', '19.999', '1,23,456.00'] as $invalid) {
            try {
                Money::parse($invalid, 'UAH');
                $this->fail("Expected \"{$invalid}\" to be rejected.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_parse_never_goes_through_a_float(): void
    {
        // 19.99 has no exact binary representation — fromDecimal() has to round its way out of it,
        // parse() composes 19 * 100 + 99 from the digits instead.
        $this->assertSame(1999, Money::parse('19.99', 'USD')->amount);
        $this->assertSame(1999, Money::fromDecimal('19.99', 'USD')->amount);
    }

    public function test_arithmetic_stays_in_minor_units(): void
    {
        $ten = new Money(1000, 'UAH');

        $this->assertSame(1500, $ten->plus(new Money(500, 'UAH'))->amount);
        $this->assertSame(500, $ten->minus(new Money(500, 'UAH'))->amount);
        $this->assertSame(5997, Money::fromDecimal('19.99', 'UAH')->multiply(3)->amount);
        $this->assertSame(333, $ten->multiply(1 / 3)->amount);
        $this->assertTrue($ten->minus($ten)->isZero());
        $this->assertTrue($ten->equals(new Money(1000, 'UAH')));
        $this->assertFalse($ten->equals(new Money(1000, 'USD')));
    }

    /** Half-up and half-even are different money — the mode is a parameter for a reason. */
    public function test_multiply_rounds_the_way_it_was_told_to(): void
    {
        // 2.5 and 3.5 minor units — the only place the modes actually disagree.
        $this->assertSame(3, (new Money(5, 'UAH'))->multiply(0.5)->amount); // half-up is the default
        $this->assertSame(3, (new Money(5, 'UAH'))->multiply(0.5, Rounding::HalfUp)->amount);
        $this->assertSame(2, (new Money(5, 'UAH'))->multiply(0.5, Rounding::HalfDown)->amount);
        $this->assertSame(2, (new Money(5, 'UAH'))->multiply(0.5, Rounding::HalfEven)->amount); // to even: 2, not 3
        $this->assertSame(4, (new Money(7, 'UAH'))->multiply(0.5, Rounding::HalfEven)->amount); // to even: 4, not 3

        $this->assertSame(3, (new Money(201, 'UAH'))->multiply(0.01, Rounding::Up)->amount);   // 2.01 → 3
        $this->assertSame(2, (new Money(299, 'UAH'))->multiply(0.01, Rounding::Down)->amount); // 2.99 → 2
    }

    public function test_arithmetic_refuses_to_mix_currencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Money(1000, 'UAH'))->plus(new Money(1000, 'USD'));
    }

    /** Money never goes below zero — the constructor is the guard, minus() just hits it. */
    public function test_subtracting_below_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Money(500, 'UAH'))->minus(new Money(501, 'UAH'));
    }

    public function test_allocate_loses_no_minor_unit(): void
    {
        $shares = (new Money(10000, 'UAH'))->allocate([1, 1, 1]);

        $this->assertSame([3334, 3333, 3333], array_map(fn (Money $m) => $m->amount, $shares));
        $this->assertSame(10000, array_sum(array_map(fn (Money $m) => $m->amount, $shares)));

        // Weighted: a 100.00 discount spread over a 70/30 basket.
        $weighted = (new Money(10000, 'UAH'))->allocate([70, 30]);
        $this->assertSame([7000, 3000], array_map(fn (Money $m) => $m->amount, $weighted));

        // One unit, three ways — the leftovers go to the earliest shares, nothing is invented.
        $crumbs = (new Money(1, 'UAH'))->allocate([1, 1, 1]);
        $this->assertSame([1, 0, 0], array_map(fn (Money $m) => $m->amount, $crumbs));
    }

    public function test_allocate_rejects_ratios_that_cannot_split_anything(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Money(1000, 'UAH'))->allocate([0, 0]);
    }

    public function test_format_falls_back_to_a_plain_string_without_intl(): void
    {
        $formatted = (new Money(129900, 'UAH'))->format('uk_UA');

        if (! extension_loaded('intl')) {
            $this->assertSame('1299.00 UAH', $formatted);

            return;
        }

        // Don't assert ICU's exact spacing/symbol — it moves between ICU versions. The digits do not.
        $this->assertStringContainsString('1', $formatted);
        $this->assertStringContainsString('299', $formatted);
    }
}
