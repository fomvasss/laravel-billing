<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Contracts\InvoiceTemplateResolver;
use Fomvasss\Billing\Models\Invoice;

final class DefaultInvoiceTemplateResolver implements InvoiceTemplateResolver
{
    public function view(Invoice $invoice): string
    {
        return $invoice->template ?? "billing::invoices.{$invoice->type->value}";
    }
}
