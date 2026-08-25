<?php

declare(strict_types=1);

namespace Fomvasss\Billing;

use Fomvasss\Billing\Console\ExpirePausesCommand;
use Fomvasss\Billing\Console\ExpireTrialsCommand;
use Fomvasss\Billing\Console\ProcessRecurringChargesCommand;
use Fomvasss\Billing\Console\ReconcilePendingPaymentsCommand;
use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Contracts\RenewalChargeOptionsContract;
use Fomvasss\Billing\Events\PaymentCanceled;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Exceptions\BillingException;
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
use Fomvasss\Billing\Http\Controllers\CheckoutFormController;
use Fomvasss\Billing\Http\Controllers\CheckoutReturnController;
use Fomvasss\Billing\Http\Controllers\WebhookController;
use Fomvasss\Billing\Listeners\HandleSubscriptionPaymentOutcome;
use Fomvasss\Billing\Support\DefaultCredentialResolver;
use Fomvasss\Billing\Support\DefaultRenewalChargeOptions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/billing.php', 'billing');

        $this->app->singleton(BillingManager::class, static fn () => new BillingManager());
        $this->app->bind(CredentialResolverContract::class, DefaultCredentialResolver::class);
        $this->app->bind(RenewalChargeOptionsContract::class, DefaultRenewalChargeOptions::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/billing.php' => config_path('billing.php'),
            ], 'billing-config');

            $this->publishMigrations();
        }

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'billing');

        $this->registerWebhookRoute();
        $this->registerCheckoutFormRoute();
        $this->registerReturnRoute();
        $this->registerPayLinkRoute();
        $this->registerFakeGateway();
        $this->registerBuiltInGateways();
        $this->registerListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessRecurringChargesCommand::class,
                ReconcilePendingPaymentsCommand::class,
                ExpireTrialsCommand::class,
                ExpirePausesCommand::class,
                \Fomvasss\Billing\Console\StripeRegisterWebhookCommand::class,
                \Fomvasss\Billing\Console\HealthCommand::class,
            ]);

            if (config('billing.schedule.enabled', false)) {
                $this->registerSchedule();
            }
        }
    }

    /**
     * Published, not auto-loaded (`loadMigrationsFrom()`) — billing schema deserves the same
     * review-before-you-run step Cashier requires, and lets a consumer skip subscriptions/
     * payment_methods entirely if they never need them. Destination filenames are the SOURCE's own
     * fixed names, not a freshly generated timestamp — `vendor:publish` skips a file that already
     * exists at that exact path without `--force`, so re-publishing is naturally idempotent (the
     * ai-tasks `date('Y_m_d_His')`-destination bug this avoids is documented in the package plan).
     */
    protected function publishMigrations(): void
    {
        $path = __DIR__ . '/../database/migrations/';

        $this->publishes([
            $path . '2026_08_15_000000_create_billing_webhook_calls_table.php' => database_path('migrations/2026_08_15_000000_create_billing_webhook_calls_table.php'),
            $path . '2026_08_15_000001_create_billing_payments_table.php' => database_path('migrations/2026_08_15_000001_create_billing_payments_table.php'),
        ], 'billing-migrations-core');

        $this->publishes([
            $path . '2026_08_15_000002_create_billing_plans_table.php' => database_path('migrations/2026_08_15_000002_create_billing_plans_table.php'),
            $path . '2026_08_15_000003_create_billing_prices_table.php' => database_path('migrations/2026_08_15_000003_create_billing_prices_table.php'),
            $path . '2026_08_15_000004_create_billing_subscriptions_table.php' => database_path('migrations/2026_08_15_000004_create_billing_subscriptions_table.php'),
        ], 'billing-migrations-subscriptions');

        $this->publishes([
            $path . '2026_08_15_000005_create_billing_payment_methods_table.php' => database_path('migrations/2026_08_15_000005_create_billing_payment_methods_table.php'),
        ], 'billing-migrations-payment-methods');
    }

    /**
     * One route for every gateway, resolved through BillingManager's registries at request time —
     * see WebhookController and "Webhook pipeline" in the package plan for why this replaced a
     * per-gateway route registered by each driver individually. Path/middleware are both
     * config-driven (billing.webhook.*) — the route name ('billing.webhook') never changes, so
     * AbstractGateway::webhookUrl() and BillingManager::gateways() keep working regardless of what
     * path a host actually mounts it under.
     */
    protected function registerWebhookRoute(): void
    {
        $path = config('billing.webhook.path', 'billing/webhooks/{gateway}');

        if (! str_contains($path, '{gateway}')) {
            throw new BillingException('config("billing.webhook.path") must contain a {gateway} route parameter.');
        }

        Route::post($path, [WebhookController::class, 'handle'])
            ->middleware((array) config('billing.webhook.middleware', []))
            ->name('billing.webhook');
    }

    /**
     * The redirect target `BillingManager::charge()` points `payment_url` at for a form-only
     * gateway (currently just LiqPay) — see CheckoutFormController and "PaymentResult — url чи
     * form" in the package plan. Registered unconditionally (unlike the fake-gateway route) since
     * LiqPay is a real, always-available built-in gateway, not local/testing-only.
     */
    protected function registerCheckoutFormRoute(): void
    {
        // 'web' — needed for SubstituteBindings to resolve {payment} into a real Payment model;
        // without it the controller gets a fresh, empty Payment (id=null) instead of a 404 on a
        // genuinely missing route param, which is the wrong kind of 404 for the wrong reason.
        Route::middleware('web')->get('billing/checkout/{payment}', [CheckoutFormController::class, 'show'])
            ->name('billing.checkout-form');
    }

    /**
     * The default browser-return target the drivers hand every gateway (see
     * AbstractGateway::successUrl()) — fires CheckoutReturned, then 303-redirects to
     * config('billing.return_urls.*'). GET+POST because WayForPay/Hutko return the customer via an
     * auto-submitted POST form — hence no `web` group (a gateway's POST can't carry a CSRF token),
     * only SubstituteBindings for {payment}.
     */
    protected function registerReturnRoute(): void
    {
        Route::match(['get', 'post'], 'billing/return/{payment}/{outcome}', CheckoutReturnController::class)
            ->whereIn('outcome', ['success', 'failed'])
            ->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
            ->name('billing.return');
    }

    /**
     * The permanent pay link for emails/invoices — redirects to a live checkout, re-issuing an
     * expired one via charge() on the fly. See PaymentLinkController.
     */
    protected function registerPayLinkRoute(): void
    {
        Route::get('billing/pay/{payment}', \Fomvasss\Billing\Http\Controllers\PaymentLinkController::class)
            ->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
            ->name('billing.pay');
    }

    protected function registerListeners(): void
    {
        Event::listen(PaymentSucceeded::class, [HandleSubscriptionPaymentOutcome::class, 'handlePaymentSucceeded']);
        Event::listen(PaymentFailed::class, [HandleSubscriptionPaymentOutcome::class, 'handlePaymentFailed']);
        Event::listen(PaymentCanceled::class, [HandleSubscriptionPaymentOutcome::class, 'handlePaymentCanceled']);
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

            // withoutOverlapping on both money-touching commands: a run outliving its interval
            // must not race a parallel one — the in-command pending-renewal guard is not atomic
            // across processes, so an overlap could double-initiate a charge.
            //
            // everyMinute, not hourly: a renewal lands within a minute of the period end ("paid at
            // 13:23 → charged 13:23 a month later"), and short-cycle billing (hourly rentals)
            // works out of the box. Safe at this frequency by construction — the query is indexed
            // (status, current_period_ends_at), the command is idempotent, overlap is locked out.
            $schedule->command('billing:process-recurring-charges')->everyMinute()->withoutOverlapping();
            // 15 min, not hourly — reconcile_after_minutes (default 60) already delays how soon a
            // stuck payment qualifies; hourly on top of that meant a real "paid but webhook lost"
            // payment could sit unresolved for up to ~2h before this ever looked at it.
            $schedule->command('billing:reconcile-pending-payments')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('billing:expire-trials')->daily();
            // Hourly, not daily like expire-trials above: a paused subscription has isActive()
            // false, so a full day's lag past $until would deny access well beyond what the
            // customer scheduled. No money moves here, so hourly costs nothing extra.
            $schedule->command('billing:expire-pauses')->hourly();
            $schedule->command('model:prune', ['--model' => [\Fomvasss\Billing\Webhooks\BillingWebhookCall::class]])->daily();
        });
    }

    /** local/testing only — BillingManager::extend() itself refuses "fake" everywhere else. */
    protected function registerFakeGateway(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            return;
        }

        $this->app->make(BillingManager::class)
            ->extend('fake', FakeGateway::class)
            ->registerWebhook('fake', FakeSignatureValidator::class);

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

        $manager->extend('monobank', MonobankGateway::class)
            ->registerWebhook('monobank', MonobankSignatureValidator::class);

        $manager->extend('liqpay', LiqPayGateway::class)
            ->registerWebhook('liqpay', LiqPaySignatureValidator::class);

        $manager->extend('wayforpay', WayForPayGateway::class)
            ->registerWebhook('wayforpay', WayForPaySignatureValidator::class, WayForPayWebhookResponder::class);

        $manager->extend('stripe', StripeGateway::class)
            ->registerWebhook('stripe', StripeSignatureValidator::class);

        $manager->extend('hutko', HutkoGateway::class)
            ->registerWebhook('hutko', HutkoSignatureValidator::class);
    }
}
