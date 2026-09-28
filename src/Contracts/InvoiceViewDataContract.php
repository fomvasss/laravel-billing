<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\Models\Invoice;

/**
 * Extra variables for the document's view, on top of $document and $invoice — data a custom
 * template needs without editing the package's view. Default: none.
 */
interface InvoiceViewDataContract
{
    /** @return array<string, mixed> */
    public function data(Invoice $invoice): array;
}
