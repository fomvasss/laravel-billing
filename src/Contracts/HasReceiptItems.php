<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

/** Optional contract on Payable — a neutral fiscal-basket structure, not a gateway-specific format. */
interface HasReceiptItems
{
    /** @return array<int, array{name: string, qty: int|float, unitAmount: int, sku?: string}> */
    public function receiptItems(): array;
}
