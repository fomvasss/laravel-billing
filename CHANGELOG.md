# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

All five built-in gateways are live-verified end to end (checkout, webhooks, tokenization, off-session charges, health probes).

### Payments extras
- `Payment::$number` — a human-facing payment reference ("PAY-2026-000123") for receipts/emails/support, with a unique index and `Payment::findByNumber()`. The package never generates it — numbering schemes are project-specific, assign yours in a `Payment::creating()` hook (see README "Payment numbers").
- `Payment::$fee` + `netAmount()` — the gateway's commission (minor units, payment currency), parsed from the payment callback/status response on Monobank, LiqPay, WayForPay and Hutko; Stripe doesn't report it in webhooks, so it stays `null` there. `null` means "unknown" (never a guessed 0); the column is consumer-writable for your own commission policy via a `PaymentSucceeded` listener (see README "Gateway fee and net amount").

### Gateways
- Built-in drivers: **LiqPay**, **WayForPay**, **Monobank Acquiring**, **Stripe**, **Hutko** — registered automatically, credentials from `config('billing.gateways.*')` (env stubs shipped). A `fake` gateway (local/testing only) runs the full pipeline without a bank.
- Add your own with `Billing::extend()` + `registerWebhook()` — one wildcard webhook route serves every gateway, no per-gateway routes or config. See `docs/writing-a-gateway.md`.
- Capability contracts, checked per driver: `RefundsPayments`, `ChecksPaymentStatus`, `TokenizesPaymentMethod`, `ChecksGatewayHealth`, `SubscriptionGatewayContract`. `Billing::gateways()` exposes label, currencies, credential fields (for a settings UI), the webhook URL, whether the gateway needs its webhook endpoint pre-registered, and the capability map.
- Currency lists per gateway with a config override (`billing.gateways.{name}.currencies`) and `Billing::supportedCurrencies($gateway)`; `resolveChargeAmount()` resolves a `Price` against them (own currency → sibling price → bound `CurrencyConverterContract` → exception).
- Checkout-link TTL per gateway (`link_ttl_minutes`) — `payment_url_expires_at` is always a real expiry.
- Health checks: `Billing::health($gateway)` and `billing:health` (non-zero exit when anything is down) — live, side-effect-free credential/reachability probes on every built-in gateway.
- `billing:stripe-register-webhook` registers the Stripe webhook endpoint via API and prints the signing secret (`--fresh` re-creates it after a domain change) — no Dashboard needed.

### Payments
- `Payment` model: UUID v7 keys, `meta` json for your own data, status/type enums, helpers (`isPaid()`, `refundedAmount()`, `hasActivePaymentUrl()`, ...) and scopes (`paid()`, `pending()`, `forBillable()`).
- `Billing::charge($payment, ChargeOptions)` — hosted checkout on any gateway; `payment_url` is always a plain redirectable link (form-only gateways are bridged through a package page). `ChargeOptions` covers description, customer email, locale, `saveCard`, per-charge return URLs, `returnParams`, fiscal `receiptItems` (auto-filled from a `HasReceiptItems` payable) and a `raw` escape hatch merged under the driver's own fields.
- `Billing::chargeWithMethod()` — off-session charge with a saved card; rejects a method from another gateway or billable before any API call.
- `Billing::refund($payment, ?Money)` — full or partial; creates a child `Payment` (`type=refund`, `parent_payment_id`), dispatches `PaymentRefunded`, caps cumulative refunds at the original amount. Supported on Monobank, LiqPay, Stripe.
- Manual/offline payments (cash, bank transfer): create a `Payment` row directly, no driver needed; `paid_at` is stamped automatically.
- Browser return: gateways send the customer through the package's return route — fires `CheckoutReturned`, then 303s to your configured success/failed pages with `?payment={id}` and any `returnParams`; absorbs the gateways that return the customer via POST. Per-charge URLs bypass it.
- Permanent pay link `route('billing.pay', $payment)` — safe for emails/invoices: redirects to a live checkout, re-issues an expired/failed one on the fly, sends a paid one to the success page; fires `PaymentLinkOpened`.

### Saved cards / tokenization
- All five gateways: the card is saved as a side effect of the first charge (`ChargeOptions::$saveCard`; WayForPay saves without the flag; Stripe does it through its hosted Checkout — no frontend code). `PaymentMethodAttached` fires once per new card; the newest card becomes the default per gateway.
- Stripe additionally saves a card *without* charging (frontend SetupIntent + `attachPaymentMethod()`).
- `PaymentMethod` model with per-gateway defaults; `detachPaymentMethod()` (Monobank also revokes at the bank).

### Subscriptions
- `Plan`/`Price`/`Subscription` models: flat/licensed/metered pricing, minute-to-year intervals (short-cycle billing works out of the box), trials, pause/resume, cancel now or at period end, plan swap, usage quotas (`included_units`, `reportUsage()` with idempotency keys, `UsageLimitReached`) orthogonal to pricing type.
- Auto-renewal: `billing:process-recurring-charges` (every minute, overlap-locked) charges the saved default card when the period ends — month-end-safe date math, an unresolved renewal blocks a double charge, period-end cancellations are finalized before billing. The first payment stamps its gateway onto a gateway-less (trial) subscription; a quota resets on every paid period.
- Dunning: configurable attempts / retry interval / grace window (`past_due` keeps `isActive()` true until grace ends); a declined card during a trial never cancels it.
- Trials: `billing:expire-trials` dispatches `TrialWillEnd` at each configured interval (`billing.trial_ending_notices`, e.g. `['7 days', '3 days', '1 day']` or `['15 minutes']`; per-`Price` override, `[]` disables; `$event->notice` says which fired) and moves expired trials to `ended` — it never takes money; converting is a normal payment against the subscription row.
- `Billable` trait: `payments()`/`subscriptions()`/`paymentMethods()` relations, `defaultPaymentMethod(For)()`, `activeSubscription()`/`hasActiveSubscription()` matching `isActive()` semantics (grace included), `tenantId()` hook for per-tenant credentials (`CredentialResolverContract`).

### Webhooks
- One route (`POST /billing/webhooks/{gateway}`, path/middleware configurable), per-gateway signature validators (fail-closed when unconfigured), durable storage with pruning (`webhook.prune_after_days`), queued processing on a configurable connection/queue (`billing.queue.*`), custom acknowledgment bodies where a gateway requires one.
- Guarantees: a paid callback must match the payment's amount/currency; events are deduplicated per outcome (a re-delivery never double-fires, a declined-then-paid retry fires both); callbacks for unknown payments are ignored, not failed jobs.
- Events: `PaymentSucceeded`/`PaymentFailed`/`PaymentRefunded`, `SubscriptionCreated`/`Renewed`/`PaymentFailed`/`Cancelled`/`Paused`/`Resumed`, `TrialWillEnd`, `PaymentMethodAttached`/`Detached`, `UsageLimitReached`, `CheckoutReturned`, `PaymentLinkOpened`.
- `billing:reconcile-pending-payments` (every 15 min) polls gateways for payments whose webhook never arrived — same events, same dedup, so a late webhook can't double-dispatch.

### Money
- Every amount is an integer in minor units; `Money::fromDecimal()`/`toDecimal()` bridge `decimal` columns safely. Two-decimal currencies only, by design.

### Setup & docs
- Migrations are published in groups (`billing-migrations-core` / `-subscriptions` / `-payment-methods`), re-publish-safe; morph id columns are strings (int and UUID billables/payables both fit).
- The schedule is off by default (`billing.schedule.enabled`); commands are idempotent and safe to re-register at any cadence.
- Docs: README (uk/en), `docs/use-cases.md` (end-to-end system designs), `docs/writing-a-gateway.md` (driver authoring), `docs/architecture.md` (internals), `docs/webhook-testing.md` (manual testing, tunnels).
