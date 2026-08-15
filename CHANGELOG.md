# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Stripe driver now implements `TokenizesPaymentMethod` — `createCustomer()`, `attachPaymentMethod()`, `chargePaymentMethod()`, `detachPaymentMethod()` — so `billing:process-recurring-charges` can actually charge a Stripe subscription off-session with a saved card. See README "Tokenization / saved cards" for the flow. LiqPay/Monobank/WayForPay don't implement it yet — their card tokens arrive asynchronously via webhook, a different shape than this contract expects.

### Changed
- `TokenizesPaymentMethod`'s methods now type-hint `Model&Billable` instead of the bare `Billable` marker interface (a real Eloquent model is required to persist `PaymentMethod.billable_type`/`billable_id`).

### Fixed
- `DTO\PaymentResult` was missing the `$raw` property that `refund()` already passed to it in three built-in drivers (Stripe/Monobank/LiqPay) — would have thrown `Unknown named parameter $raw` on the first real `refund()` call.

## [0.1.0] - 2026-08-15

### Added
- `BillingManager` (`Billing` facade) — `extend()`/`driver()`/`gateways()`/`gateway()`, `charge()`/`chargeWithMethod()` orchestration, `resolveChargeAmount()` for currency-aware pricing.
- Built-in gateways: **LiqPay**, **WayForPay**, **Monobank Acquiring**, **Stripe**, **Hutko** — registered automatically, credentials read from `config('billing.gateways.*')` by default. `fake` gateway (local/testing only) for testing the full flow without a real bank account.
- `PaymentGatewayContract` (required) and optional capability contracts — `RefundsPayments`, `ChecksPaymentStatus`, `TokenizesPaymentMethod`, `SubscriptionGatewayContract`, `HasReceiptItems` — for writing your own gateway driver.
- `AbstractGateway` base class to cut driver boilerplate to a minimum.
- Domain models: `Payment`, `Plan`, `Price`, `Subscription`, `PaymentMethod` — one-time payments and subscriptions (trials, flat/licensed/metered pricing, usage quotas) in the same package. Primary keys are UUID v7 (`HasUuids`) — sortable, no separate incrementing-ID/exposure story to manage.
- Own webhook pipeline — one route (`POST /billing/webhooks/{gateway}` by default, path and middleware both overridable via `config('billing.webhook.*')`) for every gateway, resolved through `BillingManager`'s registry; signature verification, durable storage (`billing_webhook_calls`), dedup, and a consistent set of events (`PaymentSucceeded`, `PaymentFailed`, `PaymentRefunded`, `SubscriptionCreated`, `SubscriptionRenewed`, `SubscriptionPaymentFailed`, `SubscriptionCancelled`, `SubscriptionPaused`, `SubscriptionResumed`, `TrialWillEnd`, `PaymentMethodAttached`, `PaymentMethodDetached`, `UsageLimitReached`). No `spatie/laravel-webhook-client` dependency — dropped after review, see the package plan for why.
- `CredentialResolverContract` and `CurrencyConverterContract` — bind your own implementation for dynamic per-tenant credentials or multi-currency pricing.
- Artisan commands (off by default, `billing.schedule.enabled`): `billing:process-recurring-charges`, `billing:reconcile-pending-payments`, `billing:expire-trials`.
- Manual/offline payments (cash, bank transfer) supported without a gateway driver — create a `Payment` row directly with `status: paid`.
- Migrations are published, not auto-run — `vendor:publish --tag=billing-migrations-core` (required — `payments` + `webhook_calls`) plus `-subscriptions`/`-payment-methods` (only if you use those features).
