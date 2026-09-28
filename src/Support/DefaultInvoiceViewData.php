<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Contracts\InvoiceViewDataContract;
use Fomvasss\Billing\Models\Invoice;

final class DefaultInvoiceViewData implements InvoiceViewDataContract
{
    public function data(Invoice $invoice): array
    {
        return [];
    }
}
