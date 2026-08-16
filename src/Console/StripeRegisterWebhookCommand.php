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
    protected const EVENTS = [
        'checkout.session.completed',
        'checkout.session.expired',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
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

        $existing = collect($http->get('/webhook_endpoints', ['limit' => 100])->throw()->json('data', []))
            ->where('url', $url);

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
}
