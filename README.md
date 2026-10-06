# Laravel Billing

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-billing.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-billing)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-billing.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-billing)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-billing.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-billing)

Billing and payments for Laravel: pluggable payment gateways, one-off payments, saved cards, subscriptions with trials and dunning, usage-based pricing, refunds, invoices and receipts — with a webhook pipeline that is the only thing allowed to change a payment's status. Built-in gateways: **Monobank Acquiring**, **LiqPay**, **WayForPay**, **Hutko**, **Stripe**, **Paddle**, plus a `fake` gateway for local development.

[Українською](README.uk.md)

- **One payment model for every gateway** — `payment_url` is always a plain link; a permanent pay link re-issues expired checkouts
- **Saved cards** — tokenized as a side effect of the first charge, then charged off-session
- **Subscriptions** — flat, per-seat and metered prices, minute-to-year intervals, trials, pause, plan swaps, quotas, a retry ladder with grace access; renewed by the package or by the provider (Stripe Billing, Paddle)
- **Refunds** — through the API, plus refunds made in a gateway's dashboard, recorded from webhooks
- **Invoices and receipts** — numbered PDF documents, snapshots at issue time
- **Webhook guarantees** — fail-closed signatures, amount verification, per-outcome dedup, reconciliation of lost webhooks
- **Multi-tenant** — per-tenant credentials and several accounts of one gateway
- No dependencies beyond `illuminate/*`, Octane-safe, MySQL/MariaDB, PostgreSQL, SQLite

## Requirements

- PHP ^8.3
- Laravel ^12 | ^13

## Installation

```bash
composer require fomvasss/laravel-billing

php artisan vendor:publish --tag=billing-migrations-core
php artisan vendor:publish --tag=billing-migrations-subscriptions    # Plan / Price / Subscription
php artisan vendor:publish --tag=billing-migrations-payment-methods  # saved cards
php artisan vendor:publish --tag=billing-migrations-invoices         # invoices and receipts
php artisan migrate
```

```env
BILLING_RETURN_URL_SUCCESS=https://example.com/checkout/success
BILLING_RETURN_URL_FAILED=https://example.com/checkout/failed
BILLING_SCHEDULE_ENABLED=true

MONOBANK_TOKEN=...
```

## Quick start

```php
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;

$payment = Payment::create([
    'gateway' => 'monobank',
    'amount' => 129900, // minor units — 1 299.00
    'currency' => 'UAH',
    'payable_type' => $order->getMorphClass(),
    'payable_id' => $order->id,
    'billable_type' => $user->getMorphClass(),
    'billable_id' => $user->id,
]);

Billing::charge($payment, new ChargeOptions(description: "Order #{$order->number}"));

return redirect($payment->payment_url);
```

```php
use Fomvasss\Billing\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $event->payment->payable->markAsPaid();
});
```

## Documentation

Online: **https://fomvasss.github.io/laravel-billing/** — the same pages as in [docs/](docs/index.md).

- [Installation](docs/installation.md) · [Configuration](docs/configuration.md)
- [Payments](docs/usage/payments.md) · [Return pages and the pay link](docs/usage/return-pages.md) · [Fiscal receipt items](docs/usage/fiscal-receipts.md) · [Refunds](docs/usage/refunds.md) · [Saved cards](docs/usage/saved-cards.md)
- [Subscriptions](docs/usage/subscriptions.md) · [Trials](docs/usage/trials.md) · [Renewals and dunning](docs/usage/renewals.md) · [Usage and quotas](docs/usage/usage-quotas.md) · [Provider-managed subscriptions](docs/usage/provider-managed.md)
- [Invoices and receipts](docs/usage/invoices.md) · [Webhooks](docs/usage/webhooks.md) · [Scheduled commands](docs/usage/scheduling.md) · [Tenants and multiple accounts](docs/usage/multi-tenancy.md) · [Money and currencies](docs/usage/money.md) · [Testing](docs/usage/testing.md)
- Gateways: [Overview](docs/usage/gateways.md) · [Monobank](docs/usage/gateways/monobank.md) · [LiqPay](docs/usage/gateways/liqpay.md) · [WayForPay](docs/usage/gateways/wayforpay.md) · [Hutko](docs/usage/gateways/hutko.md) · [Stripe](docs/usage/gateways/stripe.md) · [Paddle](docs/usage/gateways/paddle.md) · [Fake](docs/usage/gateways/fake.md)
- Guides: [Production checklist](docs/guides/production.md) · [Use cases](docs/guides/use-cases.md) · [Architecture](docs/guides/architecture.md) · [Writing a gateway](docs/guides/writing-a-gateway.md) · [Testing webhooks by hand](docs/guides/webhook-testing.md)
- Reference: [Billing facade](docs/reference/billing-facade.md) · [Models](docs/reference/models.md) · [Database](docs/reference/database.md) · [Events](docs/reference/events.md) · [Commands](docs/reference/commands.md) · [Contracts](docs/reference/contracts.md) · [DTOs and enums](docs/reference/dto.md) · [Routes](docs/reference/routes.md)
- [Upgrading](docs/upgrading.md) · [Changelog](CHANGELOG.md)

## License

MIT — see [LICENSE](LICENSE.md).
