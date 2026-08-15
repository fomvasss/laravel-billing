# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- `TokenizesPaymentMethod` is now implemented by all 5 built-in gateways (Stripe, Monobank, LiqPay, WayForPay, Hutko) — `billing:process-recurring-charges` can charge a saved card off-session on any of them, not just gateways you wire up yourself. Stripe's `attachPaymentMethod()` is a direct, synchronous call; the other four attach automatically once the bank confirms tokenization, after a charge with `saveCard: true` (Monobank, LiqPay) or with no opt-in needed at all (WayForPay, Hutko). See README "Tokenization / saved cards".
- `chargePaymentMethod($payment, $method, ['ip' => ..., 'description' => ...])` — pass the payer's IP for gateways that expect it on an off-session charge (LiqPay, Hutko); falls back to a placeholder if omitted.
- `Billing::charge()` now always writes a plain, redirectable link to `Payment::$payment_url` — including LiqPay, the one built-in gateway whose checkout page only accepts a client-submitted form. No more branching on `PaymentResult::$url` vs `$form` in your own code to figure out where to send the customer.
- `Payment::$meta` — a plain `json` column, opaque to the package (same idea as `Plan::$meta`). A place to say what a one-off charge was actually for (a token-package quantity, a product code, ...) without a dedicated `Payable` model when one isn't otherwise warranted. See "Recipes" in README.

### Changed
- WayForPay's `charge()` now returns `PaymentResult::$url` directly instead of `$form` — a plain redirect, not a form you have to render and submit yourself. LiqPay is now the only built-in gateway that returns `$form`.
- `TokenizesPaymentMethod`'s methods now require an actual Eloquent model (`Model&Billable`), not just the bare `Billable` interface.
- `billing:reconcile-pending-payments` now runs every 15 minutes instead of hourly (when `billing.schedule.enabled`) — it's the fallback for a payment stuck `pending` because a webhook was lost, and `reconcile_after_minutes` (default 60 min) already delays how soon a stuck payment even qualifies, so hourly on top of that meant up to ~2h before a real "paid but webhook lost" payment got noticed.

### Fixed
- `PaymentResult` was missing its `$raw` property on three built-in drivers' `refund()` — calling `refund()` on Stripe, Monobank, or LiqPay would have thrown an error.
- The `fake` gateway's local test page posted to a webhook URL that no longer existed.
- The `Billing` facade's docblock was missing `charge()`/`chargeWithMethod()`/`resolveChargeAmount()` — IDE autocomplete now covers all of it.
- `Billing::charge()` now actually fills `ChargeOptions::$receiptItems` from `$payment->payable->receiptItems()` when it implements `HasReceiptItems` and you didn't pass one yourself — documented since 0.1.0 but never wired up, so the fiscal basket silently stayed empty unless you built it by hand.

## [0.1.0] - 2026-08-15

### Added
- `BillingManager` (`Billing` facade) — `extend()`/`driver()`/`gateways()`/`gateway()`, `charge()`/`chargeWithMethod()` orchestration, `resolveChargeAmount()` for currency-aware pricing.
- Built-in gateways: **LiqPay**, **WayForPay**, **Monobank Acquiring**, **Stripe**, **Hutko** — registered automatically, credentials read from `config('billing.gateways.*')` by default. `fake` gateway (local/testing only) for testing the full flow without a real bank account.
- `PaymentGatewayContract` (required) and optional capability contracts — `RefundsPayments`, `ChecksPaymentStatus`, `TokenizesPaymentMethod`, `SubscriptionGatewayContract`, `HasReceiptItems` — for writing your own gateway driver.
- `AbstractGateway` base class to cut driver boilerplate to a minimum.
- Domain models: `Payment`, `Plan`, `Price`, `Subscription`, `PaymentMethod` — one-time payments and subscriptions (trials, flat/licensed/metered pricing, usage quotas) in the same package. Primary keys are UUID v7 (`HasUuids`) — sortable, no separate incrementing-ID/exposure story to manage.
- Own webhook pipeline — one route (`POST /billing/webhooks/{gateway}` by default, path and middleware both overridable via `config('billing.webhook.*')`) for every gateway, resolved through `BillingManager`'s registry; signature verification, durable storage (`billing_webhook_calls`), dedup, and a consistent set of events (`PaymentSucceeded`, `PaymentFailed`, `PaymentRefunded`, `SubscriptionCreated`, `SubscriptionRenewed`, `SubscriptionPaymentFailed`, `SubscriptionCancelled`, `SubscriptionPaused`, `SubscriptionResumed`, `TrialWillEnd`, `PaymentMethodAttached`, `PaymentMethodDetached`, `UsageLimitReached`). No `spatie/laravel-webhook-client` dependency — a fully self-contained implementation instead.
- `CredentialResolverContract` and `CurrencyConverterContract` — bind your own implementation for dynamic per-tenant credentials or multi-currency pricing.
- Artisan commands (off by default, `billing.schedule.enabled`): `billing:process-recurring-charges`, `billing:reconcile-pending-payments`, `billing:expire-trials`.
- Manual/offline payments (cash, bank transfer) supported without a gateway driver — create a `Payment` row directly with `status: paid`.
- Migrations are published, not auto-run — `vendor:publish --tag=billing-migrations-core` (required — `payments` + `webhook_calls`) plus `-subscriptions`/`-payment-methods` (only if you use those features).
