<?php

declare(strict_types=1);

namespace Fomvasss\Billing;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Support\DefaultCredentialResolver;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/billing.php', 'billing');

        $this->app->singleton(BillingManager::class, static fn () => new BillingManager());
        $this->app->bind(CredentialResolverContract::class, DefaultCredentialResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/billing.php' => config_path('billing.php'),
            ], 'billing-config');
        }
    }
}
