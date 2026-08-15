<?php

declare(strict_types=1);

namespace Fomvasss\Billing;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Gateways\Fake\FakeGateway;
use Fomvasss\Billing\Gateways\Fake\FakeSignatureValidator;
use Fomvasss\Billing\Gateways\LiqPay\LiqPayGateway;
use Fomvasss\Billing\Gateways\LiqPay\LiqPaySignatureValidator;
use Fomvasss\Billing\Gateways\Monobank\MonobankGateway;
use Fomvasss\Billing\Gateways\Monobank\MonobankSignatureValidator;
use Fomvasss\Billing\Gateways\Stripe\StripeGateway;
use Fomvasss\Billing\Gateways\Stripe\StripeSignatureValidator;
use Fomvasss\Billing\Gateways\WayForPay\WayForPayGateway;
use Fomvasss\Billing\Gateways\WayForPay\WayForPaySignatureValidator;
use Fomvasss\Billing\Gateways\WayForPay\WayForPayWebhookResponder;
use Fomvasss\Billing\Support\DefaultCredentialResolver;
use Fomvasss\Billing\Support\WebhookConfigRegistrar;
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
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/billing.php' => config_path('billing.php'),
            ], 'billing-config');
        }

        $this->registerFakeGateway();
        $this->registerBuiltInGateways();
    }

    /** local/testing only — BillingManager::extend() itself refuses "fake" everywhere else. */
    protected function registerFakeGateway(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            return;
        }

        $this->app->make(BillingManager::class)->extend('fake', FakeGateway::class);

        WebhookConfigRegistrar::register('fake', FakeSignatureValidator::class);

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'billing');
        $this->loadRoutesFrom(__DIR__ . '/../routes/fake.php');
    }

    /**
     * The 5 gateways priorit­ized for v1 ship inside core (see "Погоджені рішення" in the package
     * plan) — unlike third-party drivers, which register themselves via extend() from their own
     * satellite package's ServiceProvider::boot().
     */
    protected function registerBuiltInGateways(): void
    {
        $manager = $this->app->make(BillingManager::class);

        $manager->extend('monobank', MonobankGateway::class);
        WebhookConfigRegistrar::register('monobank', MonobankSignatureValidator::class);

        $manager->extend('liqpay', LiqPayGateway::class);
        WebhookConfigRegistrar::register('liqpay', LiqPaySignatureValidator::class);

        $manager->extend('wayforpay', WayForPayGateway::class);
        WebhookConfigRegistrar::register('wayforpay', WayForPaySignatureValidator::class, responder: WayForPayWebhookResponder::class);

        $manager->extend('stripe', StripeGateway::class);
        WebhookConfigRegistrar::register('stripe', StripeSignatureValidator::class);
    }
}
