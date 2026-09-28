<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

/**
 * Turns the document's HTML into PDF bytes. Default: Support\DompdfInvoiceRenderer, which needs
 * barryvdh/laravel-dompdf. Bind your own for Chromium (spatie/laravel-pdf), Gotenberg, mPDF, ...
 */
interface InvoiceRenderer
{
    public function pdf(string $html): string;
}
