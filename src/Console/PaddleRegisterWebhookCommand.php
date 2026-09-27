<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Support\WebhookTenant;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Registers this app's webhook URL as a Paddle notification destination. Unlike Stripe, Paddle
 * returns the secret key on every read, so a re-run is harmless: an existing destination for the
 * same URL gets its subscribed events brought up to date and its secret printed again.
 */
class PaddleRegisterWebhookCommand extends Command
{
    /**
     * Every event PaddleGateway::handleWebhook() acts on. Re-run the command after this list grows —
     * Paddle replaces the destination's whole list on update.
     */
    protected const EVENTS = [
        'transaction.completed',
        'transaction.canceled',
        // Refunds — ours awaiting approval, and ones issued from the Paddle dashboard.
        'adjustment.created',
        'adjustment.updated',
    ];

    protected $signature = 'billing:paddle-register-webhook
        {--url= : Override the endpoint URL (defaults to route("billing.webhook", <gateway>))}
        {--gateway=paddle : The gateway name — a second Paddle account registered via Billing::extend()}
        {--tenant= : Register for this tenant\'s Paddle account — its credentials, and ?tenant= on the URL}';

    protected $description = "Register this app's webhook endpoint in Paddle via API and print the secret key";

    public function handle(): int
    {
        $gateway = $this->option('gateway');
        $tenant = $this->option('tenant') ?: null;

        $apiKey = app(CredentialResolverContract::class)->resolve($gateway, $tenant)['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            $this->error("Paddle api_key is not configured for \"{$gateway}\"" . ($tenant !== null ? " (tenant {$tenant})." : '.'));

            return self::FAILURE;
        }

        // Paddle can't be handed a callback URL per transaction, so the tenant hint the validator
        // needs has to be part of the registered URL itself — one destination per tenant's account.
        $url = $this->option('url') ?: route('billing.webhook', array_filter(['gateway' => $gateway, WebhookTenant::QUERY_KEY => $tenant]));

        $http = Http::baseUrl(str_starts_with($apiKey, 'pdl_sdbx_') ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com')
            ->withToken($apiKey)
            ->withHeaders(['Paddle-Version' => '1'])
            ->acceptJson()
            ->timeout(15);

        $existing = $this->allDestinations($http)->firstWhere('destination', $url);

        if ($existing !== null) {
            $destination = $http->patch("/notification-settings/{$existing['id']}", [
                'subscribed_events' => self::EVENTS,
                'active' => true,
            ])->throw()->json('data');

            $this->info("Updated {$destination['id']} → {$url}");
        } else {
            $destination = $http->post('/notification-settings', [
                'description' => 'laravel-billing',
                'type' => 'url',
                'destination' => $url,
                'api_version' => 1,
                'subscribed_events' => self::EVENTS,
            ])->throw()->json('data');

            $this->info("Registered {$destination['id']} → {$url}");
        }

        $this->line('Events: ' . implode(', ', self::EVENTS));
        $this->newLine();
        if ($gateway === 'paddle' && $tenant === null) {
            $this->line('Add to your .env:');
            $this->info("PADDLE_WEBHOOK_SECRET={$destination['endpoint_secret_key']}");
        } else {
            $this->line("Store it as the webhook_secret of \"{$gateway}\"" . ($tenant !== null ? " for tenant {$tenant}" : '') . ':');
            $this->info($destination['endpoint_secret_key']);
        }

        return self::SUCCESS;
    }

    /**
     * Paginated like every Paddle list: without following `after`, an account past one page would
     * look like it has no destination for this URL and get a duplicate.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function allDestinations(PendingRequest $http): Collection
    {
        $destinations = collect();
        $after = null;

        do {
            $page = $http->get('/notification-settings', array_filter(['after' => $after]))->throw()->json();

            $destinations = $destinations->concat($page['data'] ?? []);
            $after = $destinations->last()['id'] ?? null;
        } while (($page['meta']['pagination']['has_more'] ?? false) && $after !== null);

        return $destinations;
    }
}
