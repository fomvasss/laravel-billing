<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Jobs;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Support\WebhookResultDispatcher;
use Fomvasss\Billing\Support\WebhookTenant;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * `webhookCall->name` doubles as our gateway name — the same string passed to Billing::extend()/
 * registerWebhook() and to the {gateway} route segment.
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Money outcomes are worth retrying: a deadlock or a blipped Redis between marking a payment
     * paid and firing PaymentSucceeded would otherwise leave the row paid with the order never
     * fulfilled (reconciliation only looks at pending rows, and the gateway's own re-delivery is
     * dropped by the dedup claim). Set here rather than left to the worker's --tries, which many
     * apps run at 1.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public BillingWebhookCall $webhookCall)
    {
        // null = the app's defaults; billing.queue.* lets payment webhooks run on their own
        // connection/queue so a busy default queue can't delay marking payments paid.
        $this->onConnection(config('billing.queue.connection'));
        $this->onQueue(config('billing.queue.queue'));
    }

    public function handle(): void
    {
        // The same tenant hint the validator verified against: a driver built with the default
        // tenant's credentials would make any API call handleWebhook() needs (Stripe's saveCard
        // flow retrieves the PaymentIntent) with the wrong merchant's key.
        $result = app(BillingManager::class)
            ->driver($this->webhookCall->name, WebhookTenant::fromUrl($this->webhookCall->url))
            ->handleWebhook($this->webhookCall);

        // The claim and the events it guards commit together. Without the transaction, a listener
        // throwing after the claim was stamped would leave the outcome claimed but never
        // dispatched: the retry's claim would find its own key already there, treat the delivery as
        // a duplicate and drop it — a paid payment whose PaymentSucceeded never fires. Rolling the
        // claim back with the listener's own writes makes the retry a clean re-run.
        //
        // The driver's own Payment write above stays outside on purpose: handleWebhook() may call
        // the gateway's API (Stripe's saveCard flow), and a transaction must not span an HTTP
        // round trip. Re-applying it on a retry is harmless — Payment::transitionTo() treats the
        // same outcome twice as a no-op.
        DB::transaction(function () use ($result) {
            if (! $this->claimDedup($result)) {
                return; // a duplicate delivery already claimed this external_id — don't fire events twice
            }

            WebhookResultDispatcher::dispatch($result);
        });
    }

    /** Keeps the raw delivery debuggable: without this the column only ever caught dispatch-time failures. */
    public function failed(\Throwable $exception): void
    {
        $this->webhookCall->saveException($exception);
    }

    /**
     * Idempotency without a second table (see "Webhook pipeline" in the package plan): stamp the
     * result's dedupKey() onto THIS webhook call row. If another row already holds the same
     * (name, external_id), the unique index rejects the UPDATE just like it would an INSERT.
     */
    protected function claimDedup(WebhookResult $result): bool
    {
        $key = $result->dedupKey();

        if ($key === null) {
            return true;
        }

        try {
            // Nested transaction = SAVEPOINT: Postgres aborts an entire transaction on a failed
            // statement, so the violation has to be rolled back to a savepoint of its own for the
            // surrounding transaction to stay usable.
            DB::transaction(fn () => DB::table('billing_webhook_calls')
                ->where('id', $this->webhookCall->id)
                ->update(['external_id' => $key]));
        } catch (UniqueConstraintViolationException) {
            // Laravel's cross-driver unique-violation type — a bare SQLSTATE check would miss
            // Postgres, which reports 23505 where MySQL/SQLite report 23000.
            return false;
        }

        return true;
    }
}
