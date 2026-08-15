<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Jobs;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentMethodAttached;
use Fomvasss\Billing\Events\PaymentMethodDetached;
use Fomvasss\Billing\Events\PaymentRefunded;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Events\SubscriptionCreated;
use Fomvasss\Billing\Events\SubscriptionPaymentFailed;
use Fomvasss\Billing\Events\SubscriptionRenewed;
use Fomvasss\Billing\Events\TrialWillEnd;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob as SpatieProcessWebhookJob;

/**
 * `webhookCall->name` doubles as our gateway name — the same string passed to Billing::extend()
 * and to Route::webhooks($url, $name), see WebhookConfigRegistrar.
 */
class ProcessWebhookJob extends SpatieProcessWebhookJob
{
    public function handle(): void
    {
        $result = app(BillingManager::class)
            ->driver($this->webhookCall->name)
            ->handleWebhook($this->webhookCall);

        if (! $this->claimDedup($result)) {
            return; // a duplicate delivery already claimed this external_id — don't fire events twice
        }

        $this->dispatchEvent($result);
    }

    /**
     * Idempotency without a second table (see "Webhook pipeline" in the package plan): stamp
     * external_id onto THIS webhook_calls row. If another row already holds the same
     * (name, external_id), the unique index rejects the UPDATE just like it would an INSERT.
     */
    protected function claimDedup(WebhookResult $result): bool
    {
        if ($result->externalId === null) {
            return true;
        }

        try {
            DB::table('webhook_calls')
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

    protected function dispatchEvent(WebhookResult $result): void
    {
        match ($result->type) {
            WebhookEventType::Payment => match ($result->status) {
                'succeeded' => PaymentSucceeded::dispatch($result->payment),
                'failed' => PaymentFailed::dispatch($result->payment),
                'refunded' => PaymentRefunded::dispatch($result->payment),
                default => null, // 'canceled' and anything else — nothing to notify
            },
            WebhookEventType::Subscription => match ($result->status) {
                'created' => SubscriptionCreated::dispatch($result->subscription),
                'renewed' => SubscriptionRenewed::dispatch($result->subscription),
                'payment_failed' => SubscriptionPaymentFailed::dispatch($result->subscription),
                'canceled' => SubscriptionCancelled::dispatch($result->subscription),
                'trial_will_end' => TrialWillEnd::dispatch($result->subscription),
                default => null,
            },
            WebhookEventType::PaymentMethod => match ($result->status) {
                'attached' => PaymentMethodAttached::dispatch($result->paymentMethod),
                'detached' => PaymentMethodDetached::dispatch($result->paymentMethod),
                default => null,
            },
            WebhookEventType::Ignored => null,
        };
    }
}
