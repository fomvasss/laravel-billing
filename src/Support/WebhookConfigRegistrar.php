<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Jobs\ProcessWebhookJob;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Route;
use Spatie\WebhookClient\WebhookProfile\ProcessEverythingWebhookProfile;
use Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo;

/**
 * One call per gateway package's ServiceProvider::boot(), alongside Billing::extend() —
 * registers both the spatie/laravel-webhook-client config entry and the Route::webhooks() route,
 * so a gateway author never has to touch config/webhook-client.php by hand (see "Webhook pipeline").
 */
final class WebhookConfigRegistrar
{
    /**
     * @param  string  $name  Must match the name passed to Billing::extend() — this is how
     *   ProcessWebhookJob knows which driver to resolve for an incoming call.
     * @param  class-string<\Spatie\WebhookClient\SignatureValidator\SignatureValidator>  $signatureValidator
     *   Each gateway ships its own — spatie's config-level `signing_secret` is a single static
     *   value and doesn't fit dynamic per-tenant credentials, so the validator resolves its own
     *   secret internally (via CredentialResolverContract) instead of reading $config->signingSecret.
     */
    public static function register(string $name, string $signatureValidator, ?string $url = null): void
    {
        config(['webhook-client.configs' => [
            ...config('webhook-client.configs', []),
            [
                'name' => $name,
                'signing_secret' => '',
                'signature_header_name' => '',
                'signature_validator' => $signatureValidator,
                'webhook_profile' => ProcessEverythingWebhookProfile::class,
                'webhook_response' => DefaultRespondsTo::class,
                'webhook_model' => BillingWebhookCall::class,
                'store_headers' => '*',
                'store_attachments' => false,
                'process_webhook_job' => ProcessWebhookJob::class,
            ],
        ]]);

        Route::webhooks($url ?? "billing/webhooks/{$name}", $name);
    }
}
