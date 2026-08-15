<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\Support\Money;

/**
 * Optional binding (typically an adapter over fomvasss/laravel-currency — a `suggest`, not a hard
 * dependency). If nothing is bound, conversion is simply unavailable and BillingManager throws
 * BillingException::unsupportedCurrency() rather than failing silently.
 */
interface CurrencyConverterContract
{
    public function convert(Money $amount, string $toCurrency, ?\DateTimeInterface $at = null): Money;
}
