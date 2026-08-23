# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [0.1.0] - 2026-08-23

Initial release. Pre-1.0: the public API and schema may still change between minor versions.

### Added
- Gateways: LiqPay, WayForPay, Monobank Acquiring, Stripe, Hutko (all live-verified end to end), plus a `fake` gateway for local/testing. Custom drivers via `Billing::extend()` + `registerWebhook()`, one wildcard webhook route for every gateway. Per-gateway capability contracts (`RefundsPayments`, `ChecksPaymentStatus`, `TokenizesPaymentMethod`, `ChecksGatewayHealth`, `SubscriptionGatewayContract`), `Billing::gateways()` for a settings UI, currency lists with config override and `resolveChargeAmount()` (own currency → active sibling price with the same interval/pricing type → `CurrencyConverterContract`), `link_ttl_minutes` per gateway, `Billing::health()` / `billing:health`, `billing:stripe-register-webhook`.
- Multi-merchant: credentials resolved per tenant (`CredentialResolverContract`, `tenantId()` on the billable) for outgoing calls and incoming webhooks alike — the tenant rides in the callback URL as `?tenant={id}`, added automatically.
- Payments: `Payment` model (UUID v7, status/type enums, `meta`, `number` for a human-facing reference you assign, `fee` + `netAmount()`), `Billing::charge()` with `ChargeOptions` (description, email, `customerIp`, locale, `saveCard`, return URLs, `returnParams`, fiscal `receiptItems` auto-filled from a `HasReceiptItems` payable and checked against the amount, `raw`), `Billing::chargeWithMethod()` for off-session charges, manual/offline payments, the package return route (`CheckoutReturned`), permanent pay link `route('billing.pay', $payment)` (`PaymentLinkOpened`).
- Refunds: `Billing::refund($payment, ?Money)` — full or partial, a child `Payment` row per refund, capped at the original amount, serialized per payment with a cache lock, a refused refund throws. Refunds issued outside the package (gateway dashboard, disputes) are recorded from webhooks on all five gateways. `PaymentRefunded` fires exactly once per refund row, whether it was recorded by `refund()`, a webhook, a re-delivery or a queue retry.
- Saved cards: tokenization as a side effect of the first charge on every gateway (Stripe also via SetupIntent), `PaymentMethod` with per-gateway defaults, `detachPaymentMethod()`, `PaymentMethodAttached` / `PaymentMethodDetached`.
- Subscriptions: `Plan` / `Price` / `Subscription` — flat/licensed/metered pricing, minute-to-year intervals, trials (`Price::$trial_days`, `billing:expire-trials`, `TrialWillEnd` notices), pause/resume with optional auto-resume, cancel now or at period end, plan swap, usage quotas (`reportUsage()` with idempotency keys, `UsageLimitReached`), `grace_access`, provider-managed subscriptions via `external_id`. Auto-renewal (`billing:process-recurring-charges`) under a per-subscription row lock; a period that owes nothing advances without a charge. Dunning with configurable attempts / retry interval / grace — a missing or expired card, a failed initiation and a voided attempt (`PaymentCanceled`) all take the same path; a late outcome never revives a cancelled or paused subscription.
- Webhooks: durable storage with pruning, queued processing on a configurable connection/queue with its own retries, per-gateway signature validators (fail-closed), credential headers redacted before storage, unknown gateway → 404. Guarantees: a paid callback must match amount/currency, a paid payment is never reverted by a late callback, events are deduplicated per outcome and the dedup claim commits together with your listeners. `billing:reconcile-pending-payments` polls for lost webhooks through the same dedup.
- Events: `PaymentSucceeded` / `PaymentFailed` / `PaymentRefunded` / `PaymentCanceled`, `SubscriptionCreated` / `Renewed` / `PaymentFailed` / `Cancelled` / `Paused` / `Resumed` / `AccessSuspended`, `TrialWillEnd`, `PaymentMethodAttached` / `Detached`, `UsageLimitReached`, `CheckoutReturned`, `PaymentLinkOpened`.
- `Money` value object (integer minor units, two-decimal currencies, negative amounts rejected).
- Octane-compatible, database-agnostic (MySQL/MariaDB, PostgreSQL, SQLite), grouped re-publish-safe migrations, optional scheduler (`billing.schedule.enabled`). Docs: README (uk/en), `docs/use-cases.md`, `docs/writing-a-gateway.md`, `docs/architecture.md`, `docs/webhook-testing.md`.
