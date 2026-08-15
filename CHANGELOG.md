# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Saved cards and off-session charges work on all five built-in gateways — `billing:process-recurring-charges` can now renew a subscription whichever gateway it's on. Stripe needs an explicit `attachPaymentMethod()`; the rest attach the card themselves after a charge (`saveCard: true` for Monobank and LiqPay, nothing to pass for WayForPay and Hutko). See README "Tokenization / saved cards".
- `Payment::$payment_url` is always a plain redirectable link, on every gateway — no more branching on `PaymentResult::$url` vs `$form` to work out where to send the customer.
- `Payment::$meta` — a `json` column for your own data, so a one-off charge can say what it was for without a dedicated `Payable` model. See README "Recipes".
- `Money::fromDecimal()`/`toDecimal()` — bridge between `decimal(10,2)` price columns and the minor-unit integers this package uses. See README "Money".
- `ChargeOptions::$raw` reaches the gateway request on every built-in driver — the escape hatch for gateway-specific fields (LiqPay `rro_info`, Monobank `agentFeePercent`, Stripe `automatic_tax`, ...). It can add fields the driver doesn't set, never override the amount or the merchant reference.
- `chargePaymentMethod()` accepts `['ip' => ..., 'description' => ...]` — the payer's IP for gateways that expect it off-session (LiqPay, Hutko).
- Fiscal receipts on Hutko — `receiptItems` now produce an itemised fiscal receipt instead of a single generic line.
- Model helpers: `Subscription::isActive()`/`onTrial()`/`onGracePeriod()`/`isCanceled()`/`isCancelling()`, `Payment::isPaid()`/`isPending()`/`isFailed()`/`isRefund()`/`refundedAmount()`/`hasActivePaymentUrl()`, plus `active()`/`paid()`/`pending()`/`forBillable()` scopes. `isActive()` keeps access on through the dunning grace window.
- `config/billing.php` ships a credentials stub for every built-in gateway with its `env()` keys.
- `docs/writing-a-gateway.md` — guide to adding your own gateway.

### Changed
- WayForPay's `charge()` returns `PaymentResult::$url` instead of `$form` — a plain redirect, nothing to render and submit yourself. LiqPay is now the only built-in gateway returning `$form`.
- `TokenizesPaymentMethod` requires an Eloquent model (`Model&Billable`), not the bare `Billable` interface.
- `billing:reconcile-pending-payments` runs every 15 minutes instead of hourly, so a payment left `pending` by a lost webhook is picked up sooner.

### Fixed
- `refund()` threw on Stripe, Monobank and LiqPay.
- `Billing::charge()` fills `ChargeOptions::$receiptItems` from a `HasReceiptItems` payable as documented — the fiscal basket previously stayed empty unless built by hand.
- `Billing` facade covers `charge()`/`chargeWithMethod()`/`resolveChargeAmount()` for IDE autocomplete.

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
