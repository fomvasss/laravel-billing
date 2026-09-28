<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Http\Controllers;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Models\Invoice;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class InvoiceController extends Controller
{
    /** billing.invoices.pdf — behind a temporary signed URL (Billing::invoicePdfUrl()). */
    public function pdf(Invoice $invoice, BillingManager $billing): Response
    {
        return response($billing->invoicePdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $invoice->number . '.pdf"',
        ]);
    }

    /** billing.invoices.preview — local/testing only: the HTML, for working on a template without PDF round trips. */
    public function preview(Invoice $invoice, BillingManager $billing): Response
    {
        return response($billing->renderInvoice($invoice));
    }
}
