<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Contracts\HasReceiptItems;
use Fomvasss\Billing\Contracts\InvoiceItemsContract;
use Fomvasss\Billing\Models\Payment;

final class DefaultInvoiceItems implements InvoiceItemsContract
{
    public function items(Payment $payment): array
    {
        return $payment->payable instanceof HasReceiptItems ? $payment->payable->receiptItems() : [];
    }
}
