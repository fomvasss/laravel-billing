<?php

declare(strict_types=1);

namespace Fomvasss\Billing\DTO;

use Fomvasss\Billing\Support\Money;

/**
 * BillingManager::resolveChargeAmount()'s return value — the resolved per-unit Money plus whatever
 * conversion metadata needs to land on the Payment row (payments.exchange_rate/exchange_rate_at/
 * converted_from_currency), so the caller doesn't have to re-derive it. $convertedFromCurrency is
 * null unless step 3 of the currency-resolution order ("Валюти" in the plan) actually converted.
 */
final readonly class ResolvedAmount
{
    public function __construct(
        public Money $money,
        public ?string $convertedFromCurrency = null,
        public ?float $exchangeRate = null,
        public ?\DateTimeInterface $exchangeRateAt = null,
    ) {}
}
