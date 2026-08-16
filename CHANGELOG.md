# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [0.5.0] - 2026-08-16

### Added
- Stripe supports `ChargeOptions::$saveCard` — the card is saved through the hosted Checkout (`setup_future_usage`, a per-billable Stripe customer is created/reused automatically) and the `PaymentMethod` attaches from the webhook, **no frontend code needed** — the same side-effect flow the UA gateways have (pattern proven by greespi's production Stripe subscriptions). The frontend SetupIntent + `attachPaymentMethod()` path remains for saving a card without charging.
- `Billing::supportedCurrencies($gateway)` + per-gateway config override `billing.gateways.{name}.currencies` — replaces the driver's built-in currency list (always an approximation: no gateway has a "list my currencies" API, availability is account-specific). Feeds `gateways()` and `resolveChargeAmount()` too. See README "Currency conversion".

### Fixed
- Stripe off-session charges (`chargePaymentMethod()`) no longer 400 with "Invalid boolean: 1" — form-encoded booleans must be the strings `'true'`/`'false'`, not PHP booleans (live-found on the first real token charge).
- `billing_payment_methods.expires_at` is a `DATETIME`, not `TIMESTAMP` — MySQL's TIMESTAMP caps at 2038 and card expiries already exceed it (Stripe's test card is 12/55; the first real attach crashed on it). Re-publish the migrations.
- Stripe's `supportedCurrencies()` now carries the full official presentment list (~110 codes, docs.stripe.com/currencies) instead of an 8-currency subset — UAH included (live-verified with a test-mode payment; the earlier "Stripe has no UAH" note was wrong), so `resolveChargeAmount()` no longer detours listed currencies through sibling lookups or conversion. Zero-decimal (JPY, KRW, ...), three-decimal (BHD, ...) currencies and ISK stay excluded — the package is two-decimal-only by design.

## [0.4.0] - 2026-08-16

### Fixed
- Hutko tokenization actually works: the card token must be requested (`required_rectoken`, sent when `ChargeOptions::$saveCard` is set) — previously the callback's `rectoken` always arrived empty and no card was ever saved. Found on the live test merchant. Hutko now needs `saveCard: true` like Monobank/LiqPay; WayForPay remains the only gateway saving the card without a flag.
- Hutko webhooks persist the gateway's transaction id onto `Payment::$external_id` (previously left empty for checkout payments) and use it for dedup.
- Monobank `charge()` with `saveCard: true` crashed with a TypeError when the `Payment` was freshly created in the same request (int `billable_id` attribute) — found on the first live sandbox run.

### Added
- One checkout-TTL convention across gateways: every driver takes `link_ttl_minutes` from its config block and stamps `payment_url_expires_at`. WayForPay (`orderLifetime`) and Hutko (`lifetime`) now send an explicit TTL (default 1440 min) instead of leaving expiry unknown; LiqPay's cached form-page TTL is configurable (default 60 min, was a hardcoded hour); Monobank keeps its existing `validity` (default 60 min); Stripe still reports its own session expiry. `hasActivePaymentUrl()` and the pay link's re-issue logic now rest on real numbers everywhere.
- Permanent payment link: `route('billing.pay', $payment)` — safe for emails/invoices, never goes stale (redirects to the live checkout, re-issues an expired/failed one via `charge()` on the fly, sends an already-paid one to the success page). Fires the new `PaymentLinkOpened` event on every visit. See README "Permanent payment link".
- `Billing::gateways()` entries carry `webhook_requires_dashboard_setup` (new required static `requiresDashboardWebhook()` on the gateway contract, default `false` via `AbstractGateway`) — lets a settings UI show the "paste this webhook URL into the gateway's dashboard" hint only where it's actually needed (Stripe, of the built-ins). README got a per-gateway webhook setup table.

## [0.3.0] - 2026-08-16

### Changed
- The first successful payment stamps its gateway onto a gateway-less subscription — a trial can be created with `gateway: null` (nobody knows yet how it will be paid) and auto-renewal still works after conversion, no manual field update needed.
- A successful renewal resets `current_usage` for any price with a quota (`included_units` set), not only `metered` ones — a flat "N units included per period" subscription gets its fresh allowance automatically, no consumer-side reset listener needed. Quota-less usage is still left untouched.
- The `currency_code` column is renamed to `currency` on `billing_payments` and `billing_prices` — consistent with `Money::$currency`, `converted_from_currency` and what the gateways themselves call it. Re-publish the migrations and update your `Payment`/`Price` create/read calls.

### Added
- A package-owned browser-return route: gateways now send the customer back through `billing/return/{payment}/{outcome}` by default, which fires the new `CheckoutReturned` event and 303-redirects to `config('billing.return_urls.*')` with `?payment={id}` appended. Handles WayForPay/Hutko's POST-style returns without CSRF exceptions on your side and lets `return_urls` point at a frontend/SPA origin. An explicit `ChargeOptions` successUrl/failUrl still goes to the gateway as-is. `ChargeOptions::$returnParams` forwards your own query params (an order number, say) onto the final page. See README "Return pages".
- The `Concerns\Billable` trait now carries the consumer-side accessors: `payments()`/`subscriptions()`/`paymentMethods()` morph relations, `defaultPaymentMethod` (relation; per-gateway pick via `defaultPaymentMethodFor()`), and `activeSubscription(?$planCode)`/`hasActiveSubscription(?$planCode)` with the same "entitled right now" definition as `Subscription::isActive()`. See README "`Payable` and `Billable`".

## [0.2.0] - 2026-08-16

### Security
- Webhook signature validators fail closed: a gateway with no secret configured rejects every callback (403) instead of "verifying" against an empty key — previously an attacker could forge a callback for any unconfigured built-in gateway and mark an arbitrary payment paid.
- A signed "paid" callback whose amount/currency doesn't match the `Payment` row no longer marks it paid — logged as a warning, left `pending` for review. Covers the "stale cheaper checkout link paid after the amount changed" case on all five gateways.

### Fixed
- WayForPay webhooks work against the real gateway: its callbacks arrive as raw JSON under a form content type, which the previous field reading couldn't parse — every callback was rejected 403. Signature validation, processing and the signed acknowledgment all read the raw body now.
- Renewal double-charge: an unresolved pending renewal `Payment` now blocks the next `billing:process-recurring-charges` run from debiting the card again for the same period.
- `Subscription::cancel()` (at period end) actually takes effect — `billing:process-recurring-charges` finalizes subscriptions whose `cancels_at` has passed (status → `canceled`, `SubscriptionCancelled` fires) instead of billing them for another period.
- Subscription renewals no longer fail on MySQL: `payable`/`billable` morph id columns are strings now, fitting both int keys and UUIDs (the package's own `Subscription` renewal writes a UUID there). Re-publish the migrations.
- "Declined, then the customer retried and paid" no longer swallows the success: dedup keys include the outcome, so the same gateway reference can dispatch both `PaymentFailed` and `PaymentSucceeded` (Stripe reuses the PaymentIntent across retries in one Checkout Session; WayForPay/Hutko reuse the order reference). Re-deliveries of the same outcome are still deduplicated.
- `billing:reconcile-pending-payments` shares the webhook pipeline's dedup — a poll racing a late webhook can't double-dispatch `PaymentSucceeded` (which previously meant a double period advance / double fulfillment). One failing payment no longer aborts the whole run. Stripe off-session payments (`pi_...` external ids) are polled via the PaymentIntent endpoint instead of 404ing on the sessions one.
- Duplicate webhook deliveries no longer re-fire `PaymentMethodAttached` on LiqPay/WayForPay/Hutko.
- A webhook for a payment the package doesn't know (another integration on the same merchant account) is ignored instead of becoming a failed job — on every gateway.
- A declined card during a trial no longer counts toward dunning — the trial keeps running until it converts or expires.
- Dunning retries are spaced `retry_interval_hours` apart (default 24, new config key + `next_retry_at` column) — previously a `past_due` subscription was retried on every hourly run, burning all `max_recurring_attempts` within hours instead of across the multi-day grace window.
- `webhookUrlParams` query extras no longer break Hutko webhook signature verification.
- `payment_url_expires_at` is now set for form-based gateways (LiqPay) to the cached form's TTL, so `hasActivePaymentUrl()` can't report a dead link as alive.
- `Billing::chargeWithMethod()` rejects a payment method from another gateway or another billable before any API call.
- Signature validators resolve secrets through `CredentialResolverContract`, so a custom resolver binding now covers webhooks too (still tenant-agnostic — see the note in `MonobankSignatureValidator`).
- `refund()` threw on Stripe, Monobank and LiqPay.
- `Billing::charge()` fills `ChargeOptions::$receiptItems` from a `HasReceiptItems` payable as documented — the fiscal basket previously stayed empty unless built by hand.
- `Billing` facade covers `charge()`/`chargeWithMethod()`/`refund()`/`resolveChargeAmount()` for IDE autocomplete.

### Added
- `Billing::refund()` — the missing orchestration over `RefundsPayments`: makes the gateway call, creates the child `Payment` row (`type=refund`, `parent_payment_id`) and dispatches `PaymentRefunded`. Full refund by default, partial via `Money`; cumulative refunds can't exceed the original charge. See README "Refunds".
- `TrialWillEnd` actually fires now — from `billing:expire-trials`, `trial_ending_notice_days` (default 3) before `trial_ends_at`, once per subscription (new `trial_ends_notified_at` column on `billing_subscriptions`).
- Stored webhook calls are pruned after `config('billing.webhook.prune_after_days')` (default 30) — `model:prune` is scheduled daily alongside the other commands.
- `config('billing.queue.connection'/'queue')` (`BILLING_QUEUE_CONNECTION`/`BILLING_QUEUE`) — run webhook processing on a dedicated connection/queue instead of the app defaults. See README "Horizon / Queue".
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
- `Subscription::active()` scope matches `isActive()`: `past_due` still inside the grace window counts as active.
- `resolveChargeAmount()` also considers generic (`gateway = null`) sibling prices before falling back to currency conversion.
- Removed the unused `payments.link_token` and `payments.method` columns.
- WayForPay's `charge()` returns `PaymentResult::$url` instead of `$form` — a plain redirect, nothing to render and submit yourself. LiqPay is now the only built-in gateway returning `$form`.
- `TokenizesPaymentMethod` requires an Eloquent model (`Model&Billable`), not the bare `Billable` interface.
- `billing:reconcile-pending-payments` runs every 15 minutes instead of hourly, so a payment left `pending` by a lost webhook is picked up sooner.

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
