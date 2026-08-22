<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests;

use Fomvasss\Billing\BillingServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /**
     * A gateway test whose Http::fake() pattern doesn't match the URL the driver actually calls
     * would otherwise hit the real API and pass (or fail) for reasons that have nothing to do with
     * the code under test. Anything unfaked is a bug in the test or in the URL.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            BillingServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        // sqlite in-memory by default; DB_CONNECTION=pgsql runs the SAME suite against a real
        // PostgreSQL — the package supports MySQL and Postgres alike, and sqlite masks the
        // pgsql-only failure modes (uuid cast errors on foreign references, SQLSTATE 23505).
        $app['config']->set('database.connections.testing', match (env('DB_CONNECTION', 'sqlite')) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', 'postgres'),
                'port' => env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'billing_test'),
                'username' => env('DB_USERNAME', 'default'),
                'password' => env('DB_PASSWORD', 'secret'),
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        });

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
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        // Fixture tables are real migrations, not inline Schema::create() — on a persistent server
        // (DB_CONNECTION=pgsql) RefreshDatabase's migrate:fresh must own them too, or they vanish
        // mid-run; sqlite :memory: recreates everything per test and never notices the difference.
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
