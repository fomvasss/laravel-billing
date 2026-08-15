<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Webhooks;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
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

    public static function storeWebhook(string $gateway, Request $request): self
    {
        return static::create([
            'name' => $gateway,
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => (array) $request->all(),
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
}
