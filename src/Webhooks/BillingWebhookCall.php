<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Webhooks;

use Fomvasss\Billing\Support\WebhookPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Own table (billing_webhook_calls) — no longer an extension of spatie/laravel-webhook-client's
 * webhook_calls (dropped, see the package plan's "Webhook pipeline" for why). Durable raw
 * payload+headers storage, plus the dedup key (external_id, unique with name) ProcessWebhookJob
 * claims before dispatching events.
 */
class BillingWebhookCall extends Model
{
    use HasUuids;
    use MassPrunable;

    protected $table = 'billing_webhook_calls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'payload' => 'array',
            'exception' => 'array',
        ];
    }

    /**
     * Headers are stored for debugging, minus the ones that carry a credential: this table is
     * long-lived, ends up in backups and admin screens, and a stored signature header is a replayable
     * secret. The signature has already been verified by the time this runs.
     */
    protected const REDACTED_HEADERS = ['authorization', 'stripe-signature', 'x-sign', 'cookie', 'x-api-key'];

    public static function storeWebhook(string $gateway, Request $request): self
    {
        $headers = collect($request->headers->all())
            ->map(fn (array $values, string $name) => in_array(strtolower($name), self::REDACTED_HEADERS, true) ? ['[redacted]'] : $values)
            ->all();

        return static::create([
            'name' => $gateway,
            'url' => $request->fullUrl(),
            'headers' => $headers,
            'payload' => WebhookPayload::fromRequest($request),
        ]);
    }

    public function saveException(\Throwable $exception): self
    {
        $this->exception = [
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ];

        $this->save();

        return $this;
    }

    /**
     * schedule('model:prune') — registered by BillingServiceProvider when billing.schedule.enabled.
     * Pruning also drops the rows' dedup claims, so the window is a trade-off: a gateway re-delivery
     * arriving later than prune_after_days would fire its events again. 30 days is far beyond any
     * built-in gateway's retry horizon (WayForPay's, the longest, is 4 days).
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('billing.webhook.prune_after_days', 30)));
    }
}
