<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Support\WebhookResultDispatcher;
use Illuminate\Console\Command;

/**
 * Fallback for a missed webhook or a gateway-side `expired` status (never sent as a webhook — see
 * "Webhook pipeline" in the package plan). Dispatches through dispatchOnce() so a status found
 * this way fires the exact same events a webhook would have — and never twice when the real
 * webhook races this poll.
 */
class ReconcilePendingPaymentsCommand extends Command
{
    protected $signature = 'billing:reconcile-pending-payments';

    protected $description = 'Poll gateways for payments stuck pending past their TTL';

    public function handle(BillingManager $billing): int
    {
        $cutoff = now()->subMinutes((int) config('billing.reconcile_after_minutes', 60));
        $count = 0;

        Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($payments) use ($billing, &$count) {
                foreach ($payments as $payment) {
                    try {
                        $this->reconcile($payment, $billing);
                    } catch (\Throwable $exception) {
                        // One gateway/network failure must not strand every later pending payment.
                        report($exception);
                        $this->error("Payment {$payment->id}: {$exception->getMessage()}");
                    }

                    $count++;
                }
            });

        $this->info("Reconciled {$count} pending payment(s).");

        return self::SUCCESS;
    }

    protected function reconcile(Payment $payment, BillingManager $billing): void
    {
        if ($payment->gateway === null) {
            return; // manual payment (cash/requisite) — nothing to poll
        }

        try {
            $driver = $billing->driver($payment->gateway, $payment->billable?->tenantId());
        } catch (BillingException) {
            return; // gateway no longer registered — leave it pending rather than guess
        }

        // Nothing to poll WITH: a renewal charge whose initiation never returned a gateway
        // reference (the process died mid-call). Polling by a null external_id either throws every
        // run or looks up a nonexistent order forever, while the row keeps blocking this
        // subscription's renewals through the pending guard. Past the cutoff it's a dead attempt —
        // write it off so the canceled outcome reaches dunning.
        //
        // Renewals only: a Payment the consumer created up front and charges later (the emailed
        // billing.pay link) legitimately sits pending with neither reference nor checkout URL.
        if ($payment->external_id === null
            && $payment->payment_url === null
            && $payment->payable instanceof Subscription) {
            $this->writeOff($payment);

            return;
        }

        if ($driver instanceof ChecksPaymentStatus) {
            WebhookResultDispatcher::dispatchOnce($payment->gateway, $driver->checkStatus($payment));

            return;
        }

        // No status-polling endpoint on this gateway — a TTL-expired pending payment is a dead checkout.
        $this->writeOff($payment);
    }

    /**
     * transitionTo(), not update(): a webhook may have paid this row between the query and here.
     * Dispatched through the shared dedup so a late webhook carrying the same outcome can't fire
     * PaymentCanceled a second time.
     */
    protected function writeOff(Payment $payment): void
    {
        if (! $payment->transitionTo(PaymentStatus::Canceled)) {
            return;
        }

        WebhookResultDispatcher::dispatchOnce($payment->gateway, new WebhookResult(
            type: WebhookEventType::Payment,
            status: 'canceled',
            payment: $payment,
            externalId: $payment->external_id ?? (string) $payment->id,
        ));
    }
}
