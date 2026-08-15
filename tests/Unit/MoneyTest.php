<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Unit;

use Fomvasss\Billing\Support\Money;
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
}
