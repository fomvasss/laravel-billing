<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\Models\Invoice;

/**
 * Which Blade view renders a document. Default: billing::invoices.{type}; a view name stored on the
 * invoice itself (`template`) wins. Bind your own to pick by tenant, brand, language or customer.
 */
interface InvoiceTemplateResolver
{
    public function view(Invoice $invoice): string;
}
