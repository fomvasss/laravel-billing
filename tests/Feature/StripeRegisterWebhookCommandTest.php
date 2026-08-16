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

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/webhook_endpoints')
            && $request['url'] === route('billing.webhook', ['gateway' => 'stripe'])
            && $request['enabled_events[0]'] === 'checkout.session.completed');
    }

    public function test_refuses_to_reregister_without_fresh_because_the_secret_is_unrecoverable(): void
    {
        $url = route('billing.webhook', ['gateway' => 'stripe']);

        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints?*' => Http::response(['data' => [['id' => 'we_old', 'url' => $url]]]),
        ]);

        $this->artisan('billing:stripe-register-webhook')
            ->expectsOutputToContain('Already registered: we_old')
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

    public function test_fails_cleanly_without_a_secret_key(): void
    {
        config()->set('billing.gateways.stripe.secret_key', null);
        Http::fake();

        $this->artisan('billing:stripe-register-webhook')->assertFailed();
        Http::assertNothingSent();
    }
}
