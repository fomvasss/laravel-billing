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
}
