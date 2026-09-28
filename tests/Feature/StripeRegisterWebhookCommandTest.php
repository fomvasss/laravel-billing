<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class StripeRegisterWebhookCommandTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_registers_the_endpoint_and_prints_the_secret(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints?*' => Http::response(['data' => []]),
            'https://api.stripe.com/v1/webhook_endpoints' => Http::response(['id' => 'we_1', 'secret' => 'whsec_new']),
        ]);

        $this->artisan('billing:stripe-register-webhook')
            ->expectsOutputToContain('Registered we_1')
            ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET=whsec_new')
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/webhook_endpoints')) {
                return false;
            }

            $this->assertSame(route('billing.webhook', ['gateway' => 'stripe']), $request['url']);
            // Events render in the endpoint's version — pinned to the driver's, not the account default.
            $this->assertSame(\Fomvasss\Billing\Gateways\Stripe\StripeGateway::API_VERSION, $request['api_version']);
            $this->assertSame(\Fomvasss\Billing\Gateways\Stripe\StripeGateway::API_VERSION, $request->header('Stripe-Version')[0]);

            $events = [];

            for ($i = 0; isset($request["enabled_events[{$i}]"]); $i++) {
                $events[] = $request["enabled_events[{$i}]"];
            }

            // Every event the driver acts on has to be subscribed to, or it simply never arrives —
            // charge.refunded was handled for a while before it was registered, which meant a
            // dashboard refund silently never reached refundedAmount().
            $this->assertEqualsCanonicalizing([
                'checkout.session.completed',
                'checkout.session.expired',
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'charge.refunded',
                'invoice.paid',
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted',
                'customer.subscription.paused',
                'customer.subscription.resumed',
                'customer.subscription.trial_will_end',
            ], $events);

            return true;
        });
    }

    public function test_an_existing_endpoint_gets_its_events_updated_in_place_keeping_its_secret(): void
    {
        $url = route('billing.webhook', ['gateway' => 'stripe']);

        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints?*' => Http::response(['data' => [['id' => 'we_old', 'url' => $url, 'api_version' => \Fomvasss\Billing\Gateways\Stripe\StripeGateway::API_VERSION]]]),
            'https://api.stripe.com/v1/webhook_endpoints/we_old' => Http::response(['id' => 'we_old']),
        ]);

        $this->artisan('billing:stripe-register-webhook')
            ->expectsOutputToContain('Updated the events of we_old')
            ->expectsOutputToContain('signing secret is unchanged')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/webhook_endpoints/we_old') && $request['enabled_events[0]'] === 'checkout.session.completed');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_an_endpoint_on_another_api_version_is_updated_but_flagged_for_re_creation(): void
    {
        $url = route('billing.webhook', ['gateway' => 'stripe']);

        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints?*' => Http::response(['data' => [['id' => 'we_old', 'url' => $url, 'api_version' => null]]]),
            'https://api.stripe.com/v1/webhook_endpoints/we_old' => Http::response(['id' => 'we_old']),
        ]);

        $this->artisan('billing:stripe-register-webhook')
            ->expectsOutputToContain('re-create it with --fresh')
            ->assertFailed();
    }

    public function test_fresh_deletes_the_old_endpoint_and_creates_a_new_one(): void
    {
        $url = route('billing.webhook', ['gateway' => 'stripe']);

        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints?*' => Http::response(['data' => [['id' => 'we_old', 'url' => $url]]]),
            'https://api.stripe.com/v1/webhook_endpoints/we_old' => Http::response(['deleted' => true]),
            'https://api.stripe.com/v1/webhook_endpoints' => Http::response(['id' => 'we_new', 'secret' => 'whsec_rotated']),
        ]);

        $this->artisan('billing:stripe-register-webhook', ['--fresh' => true])
            ->expectsOutputToContain('Deleted old endpoint we_old')
            ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET=whsec_rotated')
            ->assertSuccessful();
    }

    public function test_a_tenant_gets_its_own_endpoint_registered_with_its_own_key(): void
    {
        $this->app->bind(\Fomvasss\Billing\Contracts\CredentialResolverContract::class, fn () => new class implements \Fomvasss\Billing\Contracts\CredentialResolverContract {
            public function resolve(string $gateway, ?string $tenantId): array
            {
                return $tenantId === 'acme' ? ['secret_key' => 'sk_acme'] : config("billing.gateways.{$gateway}", []);
            }
        });

        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints?*' => Http::response(['data' => []]),
            'https://api.stripe.com/v1/webhook_endpoints' => Http::response(['id' => 'we_acme', 'secret' => 'whsec_acme']),
        ]);

        $this->artisan('billing:stripe-register-webhook', ['--tenant' => 'acme'])
            ->expectsOutputToContain('for tenant acme')
            ->expectsOutputToContain('whsec_acme')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer sk_acme')
            // the hint the validator picks the secret by — Stripe can't be given it per payment
            && $request['url'] === route('billing.webhook', ['gateway' => 'stripe', 'tenant' => 'acme']));
    }

    public function test_a_second_stripe_account_registers_under_its_own_gateway_name(): void
    {
        config()->set('billing.gateways.stripe_eu', ['secret_key' => 'sk_eu']);

        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints?*' => Http::response(['data' => []]),
            'https://api.stripe.com/v1/webhook_endpoints' => Http::response(['id' => 'we_eu', 'secret' => 'whsec_eu']),
        ]);

        $this->artisan('billing:stripe-register-webhook', ['--gateway' => 'stripe_eu'])
            ->expectsOutputToContain('webhook_secret of "stripe_eu"')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer sk_eu')
            && $request['url'] === route('billing.webhook', ['gateway' => 'stripe_eu']));
    }

    public function test_fails_cleanly_without_a_secret_key(): void
    {
        config()->set('billing.gateways.stripe.secret_key', null);
        Http::fake();

        $this->artisan('billing:stripe-register-webhook')->assertFailed();
        Http::assertNothingSent();
    }
}
