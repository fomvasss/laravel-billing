<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Webhooks;

use Spatie\WebhookClient\Models\WebhookCall;

/**
 * Registered as `webhook_model` in each gateway's webhook-client config entry (see
 * WebhookConfigRegistrar). Adds nothing behavioural over spatie's own WebhookCall yet — exists so
 * the package has one canonical model to extend/query against instead of the vendor's directly,
 * and so the `external_id` column (added by our migration) is a first-class, documented attribute.
 */
class BillingWebhookCall extends WebhookCall
{
    // Eloquent guesses the table from THIS class's basename ("billing_webhook_calls"), not the
    // parent's — without this override it would miss the real "webhook_calls" table entirely.
    protected $table = 'webhook_calls';

    protected $casts = [
        'headers' => 'array',
        'payload' => 'array',
        'attachments' => 'array',
        'exception' => 'array',
    ];
}
