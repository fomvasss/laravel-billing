<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests;

use Fomvasss\Billing\BillingServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Spatie\WebhookClient\WebhookClientServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            WebhookClientServiceProvider::class,
            BillingServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        // sync, not the app's real queue connection — webhook/renewal jobs run inline so
        // assertions don't need a worker or Bus::fake() dance.
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');

        $app['config']->set('billing.return_urls.success', 'https://example.test/thanks');
        $app['config']->set('billing.return_urls.failed', 'https://example.test/fail');
    }

    protected function defineDatabaseMigrations(): void
    {
        // webhook_calls must exist BEFORE our own migrations run — the package's
        // add_external_id_to_webhook_calls_table migration asserts it does (see there for why).
        $this->createFixtureTables();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * spatie/laravel-webhook-client ships create_webhook_calls_table as a .stub — never
     * auto-loaded (consumers publish it themselves, see "Webhook pipeline" in the package plan).
     * Recreated here verbatim from the stub, same reasoning as visits' test_users/test_orders.
     */
    private function createFixtureTables(): void
    {
        Schema::create('webhook_calls', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('url', 512);
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->json('attachments')->nullable();
            $table->text('exception')->nullable();
            $table->timestamps();
        });

        Schema::create('test_users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('test_orders', function ($table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }
}
