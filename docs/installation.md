# Installation

## Requirements

- PHP ^8.3
- Laravel ^12 | ^13
- MySQL/MariaDB, PostgreSQL or SQLite — the package uses no raw SQL, and its test suite runs on SQLite and PostgreSQL
- Optional: `barryvdh/laravel-dompdf` for PDF invoices and receipts (or bind your own renderer, see [Invoices](usage/invoices.md))
- Optional: `ext-intl` for localized money formatting (`Money::format()` falls back to `1299.00 UAH` without it)

## Install

```bash
composer require fomvasss/laravel-billing
```

The service provider and the `Billing` facade alias are auto-discovered.

## Migrations

Migrations are published, not auto-loaded, in groups — publish only the tables you use:

| Tag | Tables | Needed for |
|---|---|---|
| `billing-migrations-core` | `billing_webhook_calls`, `billing_payments` | Everyone |
| `billing-migrations-subscriptions` | `billing_plans`, `billing_prices`, `billing_subscriptions` | [Subscriptions](usage/subscriptions.md) |
| `billing-migrations-payment-methods` | `billing_payment_methods` | [Saved cards](usage/saved-cards.md), including subscription renewals |
| `billing-migrations-invoices` | `billing_invoices`, `billing_document_sequences` | [Invoices and receipts](usage/invoices.md) |

```bash
php artisan vendor:publish --tag=billing-migrations-core
php artisan vendor:publish --tag=billing-migrations-subscriptions    # Plan / Price / Subscription
php artisan vendor:publish --tag=billing-migrations-payment-methods  # saved cards
php artisan vendor:publish --tag=billing-migrations-invoices         # invoices and receipts
php artisan migrate
```

Files are copied under fixed names, so re-running `vendor:publish` skips a migration that is already there instead of duplicating it.

> [!WARNING]
> Package-managed subscriptions renew by charging a saved card — publish `billing-migrations-payment-methods` together with `billing-migrations-subscriptions`, or `billing:process-recurring-charges` fails on the missing table.

All tables use UUID (v7) primary keys. Morph columns (`payable_id`, `billable_id`) are `string(64)`, so your models may have integer or UUID keys.

## Config

Publish the config when you need to change more than the `.env` covers:

```bash
php artisan vendor:publish --tag=billing-config
```

Every key is described in [Configuration](configuration.md). The minimum for a real gateway is its credentials and the return URLs:

```env
BILLING_RETURN_URL_SUCCESS=https://example.com/checkout/success
BILLING_RETURN_URL_FAILED=https://example.com/checkout/failed

MONOBANK_TOKEN=...
```

> [!NOTE]
> Without `BILLING_RETURN_URL_SUCCESS` (and, for Stripe and Paddle, `BILLING_RETURN_URL_FAILED`) — and without per-charge `successUrl`/`failUrl` — `charge()` throws `BillingException`: every real gateway needs somewhere to send the customer back. The `fake` gateway doesn't.

## Other publish tags

| Tag | What |
|---|---|
| `billing-config` | `config/billing.php` |
| `billing-invoice-views` | Invoice/receipt Blade templates → `resources/views/vendor/billing/invoices` |
| `billing-lang` | Invoice translations (`uk`, `en`, `pl`, `de`) → `lang/vendor/billing` |

## Scheduler

Renewals, reconciliation of lost webhooks, trial expiry and webhook pruning are scheduled commands, **off by default** because they move money:

```env
BILLING_SCHEDULE_ENABLED=true
```

The usual Laravel cron entry (`php artisan schedule:run` every minute) must be running. See [Scheduled commands](usage/scheduling.md).

## Queue

Incoming webhooks are processed by a queued job. A worker must consume the queue — with no worker, webhooks are stored but payments never become `paid`:

```env
BILLING_QUEUE_CONNECTION=redis
BILLING_QUEUE=billing
```

See [Webhooks → Queue](usage/webhooks.md#queue).

## Webhooks

Every gateway posts to one route, `POST /billing/webhooks/{gateway}`. Monobank, LiqPay, WayForPay and Hutko receive the URL with every charge request — nothing to configure. Stripe and Paddle need it registered on their side once:

```bash
php artisan billing:stripe-register-webhook
php artisan billing:paddle-register-webhook
```

`APP_URL` must be your public HTTPS URL — the callback URL is built from it. See [Webhooks](usage/webhooks.md) and the gateway pages.

## Try it without a bank

In `local` and `testing` the `fake` gateway is registered automatically — see [Fake gateway](usage/gateways/fake.md).
