<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Jobs;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Support\WebhookResultDispatcher;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
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

    public function __construct(public BillingWebhookCall $webhookCall) {}

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
     * Idempotency without a second table (see "Webhook pipeline" in the package plan): stamp
     * external_id onto THIS webhook call row. If another row already holds the same
     * (name, external_id), the unique index rejects the UPDATE just like it would an INSERT.
     */
    protected function claimDedup(WebhookResult $result): bool
    {
        if ($result->externalId === null) {
            return true;
        }

        try {
            DB::table('billing_webhook_calls')
                ->where('id', $this->webhookCall->id)
                ->update(['external_id' => $result->externalId]);
        } catch (QueryException $exception) {
            if ((int) $exception->getCode() === 23000) {
                return false;
            }

            throw $exception;
        }

        return true;
    }
}
