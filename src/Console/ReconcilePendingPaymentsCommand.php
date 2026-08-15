<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Models\Payment;
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

        if ($driver instanceof ChecksPaymentStatus) {
            WebhookResultDispatcher::dispatchOnce($payment->gateway, $driver->checkStatus($payment));

            return;
        }

        // No status-polling endpoint on this gateway — a TTL-expired pending payment is a dead checkout.
        $payment->update(['status' => PaymentStatus::Canceled]);
    }
}
