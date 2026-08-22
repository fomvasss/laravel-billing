<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * The one manual step Stripe has that the UA gateways don't — endpoint registration — done as a
 * command instead of the Dashboard or a hand-written curl. Stripe returns the whsec_ signing
 * secret ONLY in the creation response (it can never be re-fetched), which dictates the shape:
 * create → print the secret → you paste it into STRIPE_WEBHOOK_SECRET. A re-run against an
 * already-registered URL refuses (the secret is unrecoverable) unless --fresh deletes and
 * re-creates — the tunnel-domain-changed workflow.
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
    ];

    protected $signature = 'billing:stripe-register-webhook
        {--url= : Override the endpoint URL (defaults to route("billing.webhook", stripe))}
        {--fresh : Delete existing endpoint(s) for this URL first and re-create (new whsec_)}';

    protected $description = "Register this app's webhook endpoint in Stripe via API and print the signing secret";

    public function handle(): int
    {
        $secretKey = app(CredentialResolverContract::class)->resolve('stripe', null)['secret_key'] ?? null;

        if (! is_string($secretKey) || $secretKey === '') {
            $this->error('Stripe secret_key is not configured (STRIPE_SECRET_KEY).');

            return self::FAILURE;
        }

        $url = $this->option('url') ?: route('billing.webhook', ['gateway' => 'stripe']);

        $http = Http::baseUrl('https://api.stripe.com/v1')->withToken($secretKey)->timeout(15);

        $existing = $this->allEndpoints($http)->where('url', $url);

        if ($existing->isNotEmpty() && ! $this->option('fresh')) {
            foreach ($existing as $endpoint) {
                $this->warn("Already registered: {$endpoint['id']} → {$url}");
            }
            $this->line('Stripe never re-shows a signing secret. Keep using the STRIPE_WEBHOOK_SECRET you saved at creation, or re-create with --fresh to get a new one.');

            return self::FAILURE;
        }

        foreach ($existing as $endpoint) {
            $http->delete("/webhook_endpoints/{$endpoint['id']}")->throw();
            $this->line("Deleted old endpoint {$endpoint['id']}.");
        }

        $params = ['url' => $url];
        foreach (self::EVENTS as $i => $event) {
            $params["enabled_events[{$i}]"] = $event;
        }

        $created = $http->asForm()->post('/webhook_endpoints', $params)->throw()->json();

        $this->info("Registered {$created['id']} → {$url}");
        $this->line('Events: ' . implode(', ', self::EVENTS));
        $this->newLine();
        $this->line('Add to your .env (shown ONLY now — Stripe never returns it again):');
        $this->info("STRIPE_WEBHOOK_SECRET={$created['secret']}");

        return self::SUCCESS;
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
