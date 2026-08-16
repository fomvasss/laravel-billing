<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Jobs;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Support\WebhookResultDispatcher;
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

    public function __construct(public BillingWebhookCall $webhookCall)
    {
        // null = the app's defaults; billing.queue.* lets payment webhooks run on their own
        // connection/queue so a busy default queue can't delay marking payments paid.
        $this->onConnection(config('billing.queue.connection'));
        $this->onQueue(config('billing.queue.queue'));
    }

    public function handle(): void
    {
        $result = app(BillingManager::class)
            ->driver($this->webhookCall->name)
            ->handleWebhook($this->webhookCall);

        if (! $this->claimDedup($result)) {
            return; // a duplicate delivery already claimed this external_id — don't fire events twice
        }

        WebhookResultDispatcher::dispatch($result);
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
            DB::table('billing_webhook_calls')
                ->where('id', $this->webhookCall->id)
                ->update(['external_id' => $key]);
        } catch (UniqueConstraintViolationException) {
            // Laravel's cross-driver unique-violation type — a bare SQLSTATE check would miss
            // Postgres, which reports 23505 where MySQL/SQLite report 23000.
            return false;
        }

        return true;
    }
}
