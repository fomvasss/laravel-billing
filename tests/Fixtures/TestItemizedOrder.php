<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Fixtures;

use Fomvasss\Billing\Contracts\HasReceiptItems;

class TestItemizedOrder extends TestOrder implements HasReceiptItems
{
    public function receiptItems(): array
    {
        return [
            ['name' => 'Tracker', 'qty' => 2, 'unitAmount' => 1500, 'sku' => 'TRK-1'],
            ['name' => 'Delivery', 'qty' => 1, 'unitAmount' => 500],
        ];
    }
}
