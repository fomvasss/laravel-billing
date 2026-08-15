<?php

declare(strict_types=1);

namespace Fomvasss\Billing;

use Fomvasss\Billing\Console\ExpireTrialsCommand;
use Fomvasss\Billing\Console\ProcessRecurringChargesCommand;
use Fomvasss\Billing\Console\ReconcilePendingPaymentsCommand;
use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Gateways\Fake\FakeGateway;
use Fomvasss\Billing\Gateways\Fake\FakeSignatureValidator;
use Fomvasss\Billing\Gateways\Hutko\HutkoGateway;
use Fomvasss\Billing\Gateways\Hutko\HutkoSignatureValidator;
use Fomvasss\Billing\Gateways\LiqPay\LiqPayGateway;
use Fomvasss\Billing\Gateways\LiqPay\LiqPaySignatureValidator;
use Fomvasss\Billing\Gateways\Monobank\MonobankGateway;
use Fomvasss\Billing\Gateways\Monobank\MonobankSignatureValidator;
use Fomvasss\Billing\Gateways\Stripe\StripeGateway;
use Fomvasss\Billing\Gateways\Stripe\StripeSignatureValidator;
use Fomvasss\Billing\Gateways\WayForPay\WayForPayGateway;
use Fomvasss\Billing\Gateways\WayForPay\WayForPaySignatureValidator;
use Fomvasss\Billing\Gateways\WayForPay\WayForPayWebhookResponder;
use Fomvasss\Billing\Listeners\HandleSubscriptionPaymentOutcome;
use Fomvasss\Billing\Support\DefaultCredentialResolver;
use Fomvasss\Billing\Support\WebhookConfigRegistrar;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
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
        $this->registerListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessRecurringChargesCommand::class,
                ReconcilePendingPaymentsCommand::class,
                ExpireTrialsCommand::class,
            ]);

            if (config('billing.schedule.enabled', false)) {
                $this->registerSchedule();
            }
        }
    }

    protected function registerListeners(): void
    {
        Event::listen(PaymentSucceeded::class, [HandleSubscriptionPaymentOutcome::class, 'handlePaymentSucceeded']);
        Event::listen(PaymentFailed::class, [HandleSubscriptionPaymentOutcome::class, 'handlePaymentFailed']);
    }

    /**
     * Off by default (billing.schedule.enabled) — unlike laravel-visits' equivalent, these commands
     * touch money and subscription state, so a fresh install shouldn't run them without an explicit
     * opt-in. Deferred to booted() for the same reason as visits' registerMiddleware(): a later
     * provider's own schedule registration shouldn't be able to race this one.
     */
    protected function registerSchedule(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('billing:process-recurring-charges')->hourly();
            $schedule->command('billing:reconcile-pending-payments')->hourly();
            $schedule->command('billing:expire-trials')->daily();
        });
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

        $manager->extend('hutko', HutkoGateway::class);
        WebhookConfigRegistrar::register('hutko', HutkoSignatureValidator::class);
    }
}
