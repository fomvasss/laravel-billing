<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * billing.webhook.path/middleware are read once, at route registration time
 * (BillingServiceProvider::boot()) — so the override has to be in place before the app boots,
 * not set mid-test. Kept in its own class instead of a config() call inside a test method for
 * exactly that reason.
 */
class CustomWebhookRouteTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('billing.webhook.path', 'webhook/billing/{gateway}');
        $app['config']->set('billing.webhook.middleware', ['throttle:60,1']);
    }

    public function test_the_webhook_path_and_middleware_are_configurable(): void
    {
        $route = Route::getRoutes()->getByName('billing.webhook');

        $this->assertNotNull($route);
        $this->assertSame('webhook/billing/{gateway}', $route->uri());
        $this->assertContains('throttle:60,1', $route->middleware());
    }

    public function test_route_helper_still_resolves_the_configured_path(): void
    {
        $this->assertSame(
            url('webhook/billing/fake'),
            route('billing.webhook', ['gateway' => 'fake'])
        );
    }
}
