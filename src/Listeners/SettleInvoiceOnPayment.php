<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Listeners;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Enums\InvoiceStatus;
use Fomvasss\Billing\Enums\InvoiceType;
use Fomvasss\Billing\Events\InvoicePaid;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Models\Invoice;

/**
 * Registered only with `billing.invoices.enabled` (the invoice tables are an optional migration
 * group). A paid charge settles its invoice, if it has one — or, with `auto_invoice`, gets one
 * issued already paid — and with `auto_receipt` gets its receipt. Every step is idempotent: a
 * re-delivered PaymentSucceeded changes nothing.
 *
 * Registered before HandleSubscriptionPaymentOutcome on purpose: an invoice issued here reads the
 * subscription before the renewal moves its period on, so it names the period just paid for.
 */
class SettleInvoiceOnPayment
{
    public function handle(PaymentSucceeded $event): void
    {
        $payment = $event->payment;

        if ($payment->isRefund()) {
            return;
        }

        $invoice = Invoice::query()
            ->where('type', InvoiceType::Invoice)
            ->where('payment_id', $payment->id)
            ->where('status', InvoiceStatus::Issued)
            ->first();

        if ($invoice !== null) {
            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => $payment->paid_at ?? now()]);

            InvoicePaid::dispatch($invoice);
        } elseif (config('billing.invoices.auto_invoice', false)) {
            app(BillingManager::class)->invoiceAtPayment($payment);
        }

        if (config('billing.invoices.auto_receipt', false)) {
            app(BillingManager::class)->issueReceipt($payment);
        }
    }
}
