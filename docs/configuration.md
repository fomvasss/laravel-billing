# Configuration

`config/billing.php` — publish it with `php artisan vendor:publish --tag=billing-config`. Keys without an env variable can only be changed in the published file.

## General

| Key | Env | Default | Description |
|---|---|---|---|
| `debug` | `BILLING_DEBUG` | `false` | Driver-level debug log (`AbstractGateway::log()`) through the default log channel. Dev/staging only — a driver may log request data |
| `return_urls.success` | `BILLING_RETURN_URL_SUCCESS` | `null` | Your page the customer lands on after checkout, see [Return pages](usage/return-pages.md) |
| `return_urls.failed` | `BILLING_RETURN_URL_FAILED` | `null` | Your page for a failed/cancelled checkout. Only Stripe and Paddle ever use it |
| `reconcile_after_minutes` | `BILLING_RECONCILE_AFTER_MINUTES` | `60` | How old a `pending` payment must be before `billing:reconcile-pending-payments` polls the gateway for it (or writes it off) |
| `schedule.enabled` | `BILLING_SCHEDULE_ENABLED` | `false` | Registers the package's [scheduled commands](usage/scheduling.md). Off: nothing is renewed, reconciled or expired |

> [!NOTE]
> A return URL the driver needs but nobody set (neither here nor per charge in `ChargeOptions`) makes `charge()` throw `BillingException`. Every real gateway needs `success`; Stripe and Paddle also `failed`.

## Subscriptions and dunning

| Key | Env | Default | Description |
|---|---|---|---|
| `max_recurring_attempts` | `BILLING_MAX_RECURRING_ATTEMPTS` | `4` | Charge attempts a renewal gets in total — the first one plus the retries — before the subscription is cancelled |
| `retry_intervals` | — | `['6 hours', '24 hours', '48 hours']` | Wait after each failed renewal. Entry *n* paces the wait after failure *n*; a shorter list repeats its last entry; `[]` = no retries, the first failure cancels. Per-price override `prices.retry_intervals` |
| `grace_period_days` | `BILLING_GRACE_PERIOD_DAYS` | `3` | `grace_ends_at` is stamped at `next_retry_at` + this many days |
| `grace_access` | `BILLING_GRACE_ACCESS` | `true` | Whether a `past_due` subscription keeps access (`isActive()`) while retries run. Per-price override `prices.grace_access` |
| `trial_ending_notices` | — | `['3 days']` | When `TrialWillEnd` fires before `trial_ends_at`. Per-price override `prices.trial_ending_notices` |
| `period_ending_notices` | — | `[]` | When `SubscriptionPeriodEnding` fires before `current_period_ends_at`. Empty = off. Per-price override `prices.period_ending_notices` |
| `renewal.receipt_items` | `BILLING_RENEWAL_RECEIPT_ITEMS` | `false` | Give every scheduled renewal a one-line fiscal basket, see [Fiscal receipt items](usage/fiscal-receipts.md#renewals) |

Interval entries (`retry_intervals`, `trial_ending_notices`, `period_ending_notices`) are a CarbonInterval string (`'3 days'`, `'15 minutes'`) or an int meaning minutes. An unparsable, zero or negative entry throws. A per-price override of `null` means "use the global list", `[]` means "none for this price".

With the defaults a failed renewal is retried after 6 h, 24 h and 48 h, then cancelled. A list of *n* intervals wants `max_recurring_attempts` = *n* + 1 — fewer attempts leave the last entries unused. Details — [Renewals and dunning](usage/renewals.md).

## Queue

| Key | Env | Default | Description |
|---|---|---|---|
| `queue.connection` | `BILLING_QUEUE_CONNECTION` | `null` (app default) | Connection for `ProcessWebhookJob`, the package's only queued job |
| `queue.queue` | `BILLING_QUEUE` | `null` (app default) | Queue name for it |

## Webhook route

| Key | Env | Default | Description |
|---|---|---|---|
| `webhook.path` | `BILLING_WEBHOOK_PATH` | `billing/webhooks/{gateway}` | Path of the single webhook route. Must contain `{gateway}` — otherwise the provider throws on boot |
| `webhook.middleware` | — | `[]` | Middleware for the webhook route. Deliberately outside the `web` group (no CSRF, no session) |
| `webhook.prune_after_days` | `BILLING_WEBHOOK_PRUNE_AFTER_DAYS` | `30` | Stored webhook calls older than this are deleted by the daily `model:prune`. Pruning drops their dedup claims too — keep it beyond every gateway's retry horizon (WayForPay retries for 4 days) |

The route name `billing.webhook` never changes, so callback URLs follow a changed path automatically.

## Invoices

Opt-in, see [Invoices and receipts](usage/invoices.md).

| Key | Env | Default | Description |
|---|---|---|---|
| `invoices.enabled` | `BILLING_INVOICES_ENABLED` | `false` | Registers the settle-on-payment listener and the PDF/preview routes. Needs the `billing-migrations-invoices` tables |
| `invoices.auto_receipt` | `BILLING_INVOICES_AUTO_RECEIPT` | `false` | Issue a receipt on every `PaymentSucceeded` |
| `invoices.auto_invoice` | `BILLING_INVOICES_AUTO_INVOICE` | `false` | Give a payment paid without an invoice one, issued already paid |
| `invoices.seller.name` | `BILLING_SELLER_NAME` | `null` | Seller's name. Nothing is issued automatically while it is empty |
| `invoices.seller.tax_id` | `BILLING_SELLER_TAX_ID` | `null` | ЄДРПОУ / ІПН, Steuernummer, NIP — whatever identifies the seller for tax |
| `invoices.seller.vat_id` | `BILLING_SELLER_VAT_ID` | `null` | VAT registration, VAT payers only |
| `invoices.seller.address` | `BILLING_SELLER_ADDRESS` | `null` | May span lines |
| `invoices.seller.iban` | `BILLING_SELLER_IBAN` | `null` | |
| `invoices.seller.bank` | `BILLING_SELLER_BANK` | `null` | |
| `invoices.seller.email` | `BILLING_SELLER_EMAIL` | `null` | |
| `invoices.seller.phone` | `BILLING_SELLER_PHONE` | `null` | |
| `invoices.seller.logo` | `BILLING_SELLER_LOGO` | `null` | A local file (absolute, or relative to `public/`, up to 1 MB) is embedded as `data:`; a URL is left as is — dompdf won't fetch it |
| `invoices.seller.brand` | `BILLING_SELLER_BRAND` | `null` | Name printed next to a logo that doesn't carry one |
| `invoices.locale` | `BILLING_INVOICES_LOCALE` | `uk` | Default document language |
| `invoices.number_format.invoice` | — | `INV-{Y}-{000000}` | `{Y}` year, a run of zeros in braces = the counter padded to that width, `{N}` = unpadded counter |
| `invoices.number_format.receipt` | — | `RCP-{Y}-{000000}` | |
| `invoices.number_per_tenant` | `BILLING_INVOICES_NUMBER_PER_TENANT` | `true` | A sequence per tenant (each tenant is a seller). `false` — one sequence for everybody |
| `invoices.date_format` | — | `null` | `null` = each language's own (`uk` `d.m.Y`, `en` `M j, Y`); a format here applies to all |
| `invoices.due_days` | `BILLING_INVOICES_DUE_DAYS` | `5` | Due date of an unpaid invoice, days from issue |
| `invoices.footer` | `BILLING_INVOICES_FOOTER` | `null` | Footer text |
| `invoices.storage.disk` | `BILLING_INVOICES_DISK` | `null` | Keep a copy of every generated PDF on this disk (per status) |
| `invoices.storage.path` | — | `billing/invoices` | Directory on that disk |
| `invoices.pdf_route` | `BILLING_INVOICES_PDF_ROUTE` | `true` | The signed PDF route behind `invoicePdfUrl()`. `false` removes it |
| `invoices.pdf_middleware` | — | `[]` | Extra middleware in front of the signed route, e.g. `['web', 'auth']` |
| `invoices.link_ttl_minutes` | `BILLING_INVOICES_LINK_TTL_MINUTES` | `10080` | Lifetime of a signed PDF link (a week) |

## Gateway credentials

`gateways.{name}` blocks are read by the default credential resolver (`Support\DefaultCredentialResolver` — `config("billing.gateways.{$name}")`, tenant ignored). Bind your own `CredentialResolverContract` for per-tenant or database-stored credentials, see [Tenants and multiple accounts](usage/multi-tenancy.md).

An unset gateway stays unconfigured: its webhook route answers 403 and it fails only when something charges through it.

| Key | Env | Default |
|---|---|---|
| `gateways.monobank.token` | `MONOBANK_TOKEN` | — |
| `gateways.monobank.link_ttl_minutes` | `MONOBANK_LINK_TTL_MINUTES` | `60` |
| `gateways.liqpay.public_key` | `LIQPAY_PUBLIC_KEY` | — |
| `gateways.liqpay.private_key` | `LIQPAY_PRIVATE_KEY` | — |
| `gateways.liqpay.link_ttl_minutes` | `LIQPAY_LINK_TTL_MINUTES` | `60` |
| `gateways.wayforpay.merchant_account` | `WAYFORPAY_MERCHANT_ACCOUNT` | — |
| `gateways.wayforpay.merchant_domain` | `WAYFORPAY_MERCHANT_DOMAIN` | — |
| `gateways.wayforpay.secret_key` | `WAYFORPAY_SECRET_KEY` | — |
| `gateways.wayforpay.link_ttl_minutes` | `WAYFORPAY_LINK_TTL_MINUTES` | `1440` |
| `gateways.hutko.merchant_id` | `HUTKO_MERCHANT_ID` | — |
| `gateways.hutko.secret_key` | `HUTKO_SECRET_KEY` | — |
| `gateways.hutko.link_ttl_minutes` | `HUTKO_LINK_TTL_MINUTES` | `1440` |
| `gateways.stripe.secret_key` | `STRIPE_SECRET_KEY` | — |
| `gateways.stripe.webhook_secret` | `STRIPE_WEBHOOK_SECRET` | — |
| `gateways.stripe.proration_behavior` | `STRIPE_PRORATION_BEHAVIOR` | `create_prorations` |
| `gateways.paddle.api_key` | `PADDLE_API_KEY` | — |
| `gateways.paddle.client_token` | `PADDLE_CLIENT_TOKEN` | — |
| `gateways.paddle.checkout_url` | `PADDLE_CHECKOUT_URL` | — |
| `gateways.paddle.webhook_secret` | `PADDLE_WEBHOOK_SECRET` | — |
| `gateways.paddle.tax_category` | `PADDLE_TAX_CATEGORY` | `standard` |
| `gateways.paddle.proration_billing_mode` | `PADDLE_PRORATION_BILLING_MODE` | `prorated_immediately` |
| `gateways.paddle.link_ttl_minutes` | `PADDLE_LINK_TTL_MINUTES` | `1440` |

What each key means — on the gateway pages. Two optional keys work in any gateway block:

- `currencies` — `['UAH', 'USD']` replaces the driver's built-in currency list (narrow it to what your merchant account has, or extend it), see [Money and currencies](usage/money.md#supported-currencies)
- `seller` — an array with the same keys as `invoices.seller`, the issuer of documents for payments through this gateway (a merchant account of another legal entity), see [Invoices](usage/invoices.md#seller-and-buyer)

`link_ttl_minutes` is the checkout-link lifetime each driver sends to the gateway and mirrors into `payments.payment_url_expires_at`. Stripe has no such key — its Checkout Session reports its own `expires_at`.

The same field list is available at runtime for a settings UI: `MonobankGateway::credentialFields()`, or `Billing::gateways()[$name]['credential_fields']` — see [Gateways overview](usage/gateways.md).
