<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Gateways\Stripe\StripeGateway;
use Fomvasss\Billing\Support\WebhookTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * The one manual step Stripe has that the UA gateways don't — endpoint registration — done as a
 * command instead of the Dashboard or a hand-written curl. Stripe returns the whsec_ signing
 * secret ONLY in the creation response (it can never be re-fetched), which dictates the shape:
 * create → print the secret → you paste it into STRIPE_WEBHOOK_SECRET. A re-run against an
 * already-registered URL updates its event list in place (the secret survives an update);
 * --fresh deletes and re-creates it with a new secret — for an API version change or the
 * tunnel-domain-changed workflow.
 */
class StripeRegisterWebhookCommand extends Command
{
    /** Must stay in sync with the event types StripeGateway::handleWebhook() actually handles. */
    /**
     * Every event StripeGateway::handleWebhook() acts on. An endpoint registered before this list
     * grew keeps its old subscription — Stripe doesn't update it retroactively, so re-run with
     * --fresh (and swap in the new signing secret) to pick up additions.
     */
    protected const EVENTS = [
        'checkout.session.completed',
        'checkout.session.expired',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        // Refunds issued from the Stripe dashboard, and chargebacks — without this a refund that
        // didn't go through Billing::refund() never reaches us and refundedAmount() understates it.
        'charge.refunded',
        // Provider-managed subscriptions: the lifecycle, and the paid invoice as proof of a period.
        'invoice.paid',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
        'customer.subscription.trial_will_end',
    ];

    protected $signature = 'billing:stripe-register-webhook
        {--url= : Override the endpoint URL (defaults to route("billing.webhook", <gateway>))}
        {--gateway=stripe : The gateway name — a second Stripe account registered via Billing::extend()}
        {--tenant= : Register for this tenant\'s Stripe account — its credentials, and ?tenant= on the URL}
        {--fresh : Delete existing endpoint(s) for this URL first and re-create (new whsec_)}';

    protected $description = "Register this app's webhook endpoint in Stripe via API and print the signing secret";

    public function handle(): int
    {
        $gateway = $this->option('gateway');
        $tenant = $this->option('tenant') ?: null;

        $secretKey = app(CredentialResolverContract::class)->resolve($gateway, $tenant)['secret_key'] ?? null;

        if (! is_string($secretKey) || $secretKey === '') {
            $this->error("Stripe secret_key is not configured for \"{$gateway}\"" . ($tenant !== null ? " (tenant {$tenant})." : '.'));

            return self::FAILURE;
        }

        // Stripe can't be handed a callback URL per payment, so the tenant hint the validator needs
        // has to be part of the registered URL itself — one endpoint per tenant's account.
        $url = $this->option('url') ?: route('billing.webhook', array_filter(['gateway' => $gateway, WebhookTenant::QUERY_KEY => $tenant]));

        $http = Http::baseUrl('https://api.stripe.com/v1')
            ->withToken($secretKey)
            ->withHeaders(['Stripe-Version' => StripeGateway::API_VERSION])
            ->timeout(15);

        $existing = $this->allEndpoints($http)->where('url', $url);

        // Already there: bring its event list up to date in place — the signing secret survives an
        // update, and Stripe never shows it again, so re-creating is only for what an update can't
        // change (the API version).
        if ($existing->isNotEmpty() && ! $this->option('fresh')) {
            $failed = false;

            foreach ($existing as $endpoint) {
                $http->asForm()->post("/webhook_endpoints/{$endpoint['id']}", $this->eventParams())->throw();
                $this->info("Updated the events of {$endpoint['id']} → {$url}");

                // An endpoint made before the driver pinned its version renders events in the
                // account default — only re-creating it changes that.
                if (($endpoint['api_version'] ?? null) !== StripeGateway::API_VERSION) {
                    $this->warn('It renders events in API version ' . ($endpoint['api_version'] ?? 'account default') . ', the driver expects ' . StripeGateway::API_VERSION . ' — re-create it with --fresh.');
                    $failed = true;
                }
            }

            $this->line('Events: ' . implode(', ', self::EVENTS));
            $this->line('The signing secret is unchanged — keep the webhook_secret you saved at creation.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($existing as $endpoint) {
            $http->delete("/webhook_endpoints/{$endpoint['id']}")->throw();
            $this->line("Deleted old endpoint {$endpoint['id']}.");
        }

        // Events are rendered in the endpoint's version, not the request's — without this the
        // payloads would follow the account default, whatever the driver itself is pinned to.
        $params = ['url' => $url, 'api_version' => StripeGateway::API_VERSION, ...$this->eventParams()];

        $created = $http->asForm()->post('/webhook_endpoints', $params)->throw()->json();

        $this->info("Registered {$created['id']} → {$url}");
        $this->line('Events: ' . implode(', ', self::EVENTS));
        $this->newLine();
        if ($gateway === 'stripe' && $tenant === null) {
            $this->line('Add to your .env (shown ONLY now — Stripe never returns it again):');
            $this->info("STRIPE_WEBHOOK_SECRET={$created['secret']}");
        } else {
            $this->line("Store it as the webhook_secret of \"{$gateway}\"" . ($tenant !== null ? " for tenant {$tenant}" : '') . ' (shown ONLY now — Stripe never returns it again):');
            $this->info($created['secret']);
        }

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    protected function eventParams(): array
    {
        $params = [];

        foreach (self::EVENTS as $i => $event) {
            $params["enabled_events[{$i}]"] = $event;
        }

        return $params;
    }

    /**
     * Paginated: Stripe caps a page at 100, and an account past that would look like it has no
     * endpoint registered — this command would then keep creating duplicates, and --fresh would
     * leave the real one behind.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function allEndpoints(\Illuminate\Http\Client\PendingRequest $http): \Illuminate\Support\Collection
    {
        $endpoints = collect();
        $startingAfter = null;

        do {
            $page = $http->get('/webhook_endpoints', array_filter([
                'limit' => 100,
                'starting_after' => $startingAfter,
            ]))->throw()->json();

            $endpoints = $endpoints->concat($page['data'] ?? []);
            $startingAfter = $endpoints->last()['id'] ?? null;
        } while (($page['has_more'] ?? false) && $startingAfter !== null);

        return $endpoints;
    }
}
