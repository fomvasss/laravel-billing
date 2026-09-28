<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Contracts\InvoiceRenderer;
use Fomvasss\Billing\Exceptions\BillingException;

/**
 * The default PDF engine — pure PHP, nothing to install on the server. Needs barryvdh/laravel-dompdf
 * (a composer suggestion, not a requirement). The default templates stick to tables and plain CSS,
 * which is what dompdf renders, and to DejaVu Sans, which carries Cyrillic.
 */
final class DompdfInvoiceRenderer implements InvoiceRenderer
{
    public function pdf(string $html): string
    {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new BillingException('PDF documents need barryvdh/laravel-dompdf (composer require barryvdh/laravel-dompdf) — or bind your own Fomvasss\Billing\Contracts\InvoiceRenderer.');
        }

        // Subsetting embeds only the glyphs used — without it DejaVu goes in whole, ~800 KB a page.
        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }
}
