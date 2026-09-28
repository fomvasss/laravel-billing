<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\DTO\BillingDetails;
use Fomvasss\Billing\DTO\InvoiceDocument;
use Fomvasss\Billing\Enums\InvoiceStatus;
use Fomvasss\Billing\Models\Invoice;

/** Builds the view's $document from the invoice's snapshot — never from the live payment or billable. */
final class InvoiceDocumentFactory
{
    public static function make(Invoice $invoice): InvoiceDocument
    {
        $locale = $invoice->locale ?? (string) config('billing.invoices.locale', app()->getLocale());
        $money = fn (int $amount) => (new Money($amount, $invoice->currency))->format($locale);
        $format = (string) (config('billing.invoices.date_format') ?? __('billing::invoice.date_format', [], $locale));
        $date = fn ($value) => $value?->copy()->locale($locale)->translatedFormat($format);
        $day = fn (?string $value) => $value === null ? null : $date(\Illuminate\Support\Carbon::parse($value));

        return new InvoiceDocument(
            type: $invoice->type->value,
            number: $invoice->number,
            status: $invoice->status->value,
            seller: self::withEmbeddedLogo($invoice->sellerDetails()),
            buyer: $invoice->buyerDetails(),
            items: array_map(fn (array $item) => [
                'name' => (string) $item['name'],
                'qty' => $item['qty'],
                'unitPrice' => $money((int) $item['unitAmount']),
                'total' => $money((int) $item['total']),
                'sku' => $item['sku'] ?? null,
                'period' => isset($item['period']['starts_at'], $item['period']['ends_at'])
                    ? $day($item['period']['starts_at']) . ' – ' . $day($item['period']['ends_at'])
                    : null,
            ], $invoice->items ?? []),
            total: $money($invoice->total),
            currency: $invoice->currency,
            issuedAt: (string) $date($invoice->issued_at),
            dueAt: $date($invoice->due_at),
            paidAt: $date($invoice->paid_at),
            payUrl: $invoice->isInvoice() && $invoice->status === InvoiceStatus::Issued && $invoice->payment_id !== null
                ? route('billing.pay', $invoice->payment_id)
                : null,
            invoiceNumber: $invoice->isReceipt() ? $invoice->invoice?->number : null,
            footer: $invoice->extra['footer'] ?? config('billing.invoices.footer'),
            locale: $locale,
            extra: $invoice->extra ?? [],
            plan: $invoice->extra['subscription']['plan'] ?? null,
            periodStartsAt: $day($invoice->extra['subscription']['period_starts_at'] ?? null),
            periodEndsAt: $day($invoice->extra['subscription']['period_ends_at'] ?? null),
            paymentMethod: $invoice->extra['payment_method'] ?? null,
        );
    }

    /**
     * A logo given as a local file (absolute, or relative to public/) becomes a data: URI — the one
     * form both the browser preview and dompdf show without a network request (dompdf doesn't fetch
     * remote images unless told to, on purpose: a renderer that follows arbitrary URLs is an SSRF).
     * A URL is left as it is: the preview and a Chromium renderer load it, dompdf won't.
     */
    private static function withEmbeddedLogo(BillingDetails $seller): BillingDetails
    {
        $logo = $seller->logo;

        if ($logo === null || preg_match('#^(https?:|data:)#i', $logo)) {
            return $seller;
        }

        $path = is_file($logo) ? $logo : public_path(ltrim($logo, '/'));
        $mime = is_file($path) && filesize($path) <= 1024 * 1024 ? mime_content_type($path) : false;

        if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
            return new BillingDetails(...[...get_object_vars($seller), 'logo' => null]);
        }

        return new BillingDetails(...[...get_object_vars($seller), 'logo' => 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path))]);
    }
}
