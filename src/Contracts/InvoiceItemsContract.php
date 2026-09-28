<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\Models\Payment;

/**
 * What a document lists. Default (Support\DefaultInvoiceItems): the payable's receiptItems() when it
 * implements HasReceiptItems. Bind your own when the lines come from elsewhere — the same source
 * as your fiscal basket, say, so the receipt, the checkout and the PDF name a purchase alike.
 * Return [] to fall back to one line for the whole amount. The items must add up to the payment.
 */
interface InvoiceItemsContract
{
    /** @return array<int, array{name: string, qty: int|float, unitAmount: int, sku?: string}> */
    public function items(Payment $payment): array;
}
