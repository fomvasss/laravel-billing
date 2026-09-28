<?php

declare(strict_types=1);

namespace Fomvasss\Billing\DTO;

/**
 * What a document's view gets as $document — the snapshot, ready to print: amounts already
 * formatted for the document's locale, dates as strings. Its fields are the package's contract
 * with your templates; build on them rather than on the live models ($invoice is there too, for
 * context the snapshot doesn't carry).
 */
final readonly class InvoiceDocument
{
    /**
     * @param  list<array{name: string, qty: int|float, unitPrice: string, total: string, sku: ?string, period: ?string}>  $items
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $type,
        public string $number,
        public string $status,
        public BillingDetails $seller,
        public BillingDetails $buyer,
        public array $items,
        public string $total,
        public string $currency,
        public string $issuedAt,
        public ?string $dueAt,
        public ?string $paidAt,
        /** The permanent pay link (billing.pay) — null once paid, or without a payment. */
        public ?string $payUrl,
        /** A receipt's invoice number, when it settles one. */
        public ?string $invoiceNumber,
        public ?string $footer,
        public string $locale,
        public array $extra,
        /** For a subscription payment: the plan's name and, on an invoice, the paid period — null otherwise. */
        public ?string $plan = null,
        public ?string $periodStartsAt = null,
        public ?string $periodEndsAt = null,
        /** A receipt's "how it was paid": the gateway's name as it was at issue time. */
        public ?string $paymentMethod = null,
    ) {}
}
