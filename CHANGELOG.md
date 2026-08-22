# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

All five built-in gateways are live-verified end to end (checkout, webhooks, tokenization, off-session charges, health probes).

### Payments extras
- `Payment::$number` — a human-facing payment reference ("PAY-2026-000123") for receipts/emails/support, with a unique index and `Payment::findByNumber()`. The package never generates it — numbering schemes are project-specific, assign yours in a `Payment::creating()` hook (see README "Payment numbers").
- `Payment::$fee` + `netAmount()` — the gateway's commission (minor units, payment currency), parsed from the payment callback/status response on Monobank, LiqPay, WayForPay and Hutko; Stripe doesn't report it in webhooks, so it stays `null` there. `null` means "unknown" (never a guessed 0); the column is consumer-writable for your own commission policy via a `PaymentSucceeded` listener (see README "Gateway fee and net amount").

### Gateways
- Stripe: a `payment_intent.payment_failed` for a PaymentIntent inside a live Checkout Session is ignored rather than failing the payment — the customer is still on the page and free to try another card; `checkout.session.expired` is that flow's terminal signal. An off-session intent's decline stays terminal.
- Built-in drivers: **LiqPay**, **WayForPay**, **Monobank Acquiring**, **Stripe**, **Hutko** — registered automatically, credentials from `config('billing.gateways.*')` (env stubs shipped). A `fake` gateway (local/testing only) runs the full pipeline without a bank.
- Add your own with `Billing::extend()` + `registerWebhook()` — one wildcard webhook route serves every gateway, no per-gateway routes or config. See `docs/writing-a-gateway.md`.
- Off-session charges and refunds are sent exactly once — no transport-level retry, because Laravel's retry fires on a timeout too and a timeout says nothing about whether the bank already moved the money. Stripe sends an `Idempotency-Key` instead (a fresh one per `refund()` call, so two deliberate partial refunds of the same amount both go through).
- Capability contracts, checked per driver: `RefundsPayments`, `ChecksPaymentStatus`, `TokenizesPaymentMethod`, `ChecksGatewayHealth`, `SubscriptionGatewayContract`. `Billing::gateways()` exposes label, currencies, credential fields (for a settings UI), the webhook URL, whether the gateway needs its webhook endpoint pre-registered, and the capability map.
- Currency lists per gateway with a config override (`billing.gateways.{name}.currencies`) and `Billing::supportedCurrencies($gateway)`; `resolveChargeAmount()` resolves a `Price` against them (own currency → sibling price → bound `CurrencyConverterContract` → exception); a sibling is the same offer in another currency, so it must be active and match on interval/interval_count/pricing_type.
- Checkout-link TTL per gateway (`link_ttl_minutes`) — `payment_url_expires_at` is always a real expiry.
- Health checks: `Billing::health($gateway)` and `billing:health` (non-zero exit when anything is down) — live, side-effect-free credential/reachability probes on every built-in gateway.
- `billing:stripe-register-webhook` registers the Stripe webhook endpoint via API and prints the signing secret (`--fresh` re-creates it after a domain change) — no Dashboard needed.

### Payments
- `Payment` model: UUID v7 keys, `meta` json for your own data, status/type enums, helpers (`isPaid()`, `refundedAmount()`, `hasActivePaymentUrl()`, ...) and scopes (`paid()`, `pending()`, `forBillable()`).
- `Billing::charge($payment, ChargeOptions)` — hosted checkout on any gateway; `payment_url` is always a plain redirectable link (form-only gateways are bridged through a package page). `ChargeOptions` covers description, customer email, locale, `saveCard`, per-charge return URLs, `returnParams`, fiscal `receiptItems` (auto-filled from a `HasReceiptItems` payable; the basket must total the payment's `amount` — Stripe bills its line items, not your amount — or the charge is refused before the gateway is called) and a `raw` escape hatch merged under the driver's own fields.
- `Billing::chargeWithMethod()` — off-session charge with a saved card; rejects a method from another gateway or billable before any API call. Now takes a full `ChargeOptions` (was a bare array) and gets the exact same `receiptItems` auto-fill as `charge()` — an overage/top-up/postpaid charge fiscalizes like a redirect checkout, as long as its payable implements `HasReceiptItems`. A scheduled subscription renewal's payable is always the package's own `Subscription`, which doesn't implement it — pass `receiptItems` explicitly if a renewal needs a receipt.
- `Billing::refund($payment, ?Money)` — full or partial; creates a child `Payment` (`type=refund`, `parent_payment_id`) carrying the refund's own gateway reference, dispatches `PaymentRefunded`, caps cumulative refunds at the original amount (soft-deleted refund rows included). Concurrent calls for one payment are serialized with a cache lock, so two racing callers can't both pass the remainder check — this needs a shared cache store, see README "Refunds". A refund the gateway refuses throws instead of being recorded (Monobank/LiqPay/Stripe all answer a refusal with HTTP 200 and a status field). Supported on Monobank, LiqPay, Stripe.
- Manual/offline payments (cash, bank transfer): create a `Payment` row directly, no driver needed; `paid_at` is stamped automatically.
- Browser return: gateways send the customer through the package's return route — fires `CheckoutReturned`, then 303s to your configured success/failed pages with `?payment={id}` and any `returnParams`; absorbs the gateways that return the customer via POST. Per-charge URLs bypass it.
- Permanent pay link `route('billing.pay', $payment)` — safe for emails/invoices: redirects to a live checkout, re-issues an expired/failed one on the fly (serialized per payment, so concurrent visits can't leave two live invoices on the gateway for one row), sends a paid one to the success page; fires `PaymentLinkOpened`.

### Saved cards / tokenization
- All five gateways: the card is saved as a side effect of the first charge (`ChargeOptions::$saveCard`; WayForPay saves without the flag; Stripe does it through its hosted Checkout — no frontend code). `PaymentMethodAttached` fires once per new card; the newest card becomes the default per gateway.
- Stripe additionally saves a card *without* charging (frontend SetupIntent + `attachPaymentMethod()`).
- `PaymentMethod` model with per-gateway defaults; `detachPaymentMethod()` (Monobank also revokes at the bank).

### Subscriptions
- `Plan`/`Price`/`Subscription` models: flat/licensed/metered pricing, minute-to-year intervals (short-cycle billing works out of the box), trials, pause/resume, cancel now or at period end, plan swap, usage quotas (`included_units`, `reportUsage()` with idempotency keys, `UsageLimitReached`) orthogonal to pricing type. `meta` json on both `Plan` and `Price` for your own storefront data (feature lists, delivery cadence, ...) — stored, never read by the package.
- `Subscription::pause($until)` takes an optional resume date — `billing:expire-pauses` (hourly) resumes it automatically once `pause_ends_at` passes; omit it for an indefinite pause that only a manual `resume()` ends.
- Auto-renewal: `billing:process-recurring-charges` (every minute, overlap-locked) charges the saved default card when the period ends — month-end-safe date math, period-end cancellations are finalized before billing. Whether a period gets charged is decided under a row lock on the subscription, so an unresolved renewal blocks a double charge even when a manual run races the scheduled one. The first payment stamps its gateway onto a gateway-less (trial) subscription; a quota resets on every paid period.
- Dunning: configurable attempts / retry interval / grace window (`past_due` keeps `isActive()` true until grace ends); a declined card during a trial never cancels it. Every way a renewal can come up short reaches the same grace/retry treatment instead of stalling the subscription in `active` on an expired period: no saved card at all, an initiation that never reached the gateway (timeout, gateway 5xx), and an attempt the gateway later voided or expired (`PaymentCanceled`).
- A renewal that owes nothing — a metered period with no usage, a licensed one down to zero seats — advances the period directly instead of attempting a zero debit every gateway rejects.
- A payment outcome only moves a subscription that is still running: a late or replayed success no longer revives a cancelled one or cuts a scheduled pause short, and a late failure no longer drags a cancelled/paused subscription into dunning (`Subscription::recordRenewalSuccess()`, the counterpart of `recordRenewalFailure()`).
- `config('billing.grace_access')` (default `true`, per-`Price` override): whether `isActive()` keeps a `past_due` subscription entitled through the grace window, or cuts it the moment the first renewal fails — the retry cycle itself is unaffected either way. `SubscriptionAccessSuspended` fires once when access is cut immediately.
- Provider-managed subscriptions (a driver implementing `SubscriptionGatewayContract`, Stripe-Billing-style): `subscriptions.external_id` marks the gateway as the lifecycle owner (`Subscription::isProviderManaged()`) — the package's schedulers skip such rows, so its charges/cancellations/trial notices never race the provider's. Per subscription, not per gateway: both models coexist on one driver. No built-in driver implements it yet.
- Trials: `billing:expire-trials` dispatches `TrialWillEnd` at each configured interval (`billing.trial_ending_notices`, e.g. `['7 days', '3 days', '1 day']` or `['15 minutes']`; per-`Price` override, `[]` disables; `$event->notice` says which fired) and moves expired trials to `ended` — it never takes money; converting is a normal payment against the subscription row.
- `Billable` trait: `payments()`/`subscriptions()`/`paymentMethods()` relations, `defaultPaymentMethod(For)()`, `activeSubscription()`/`hasActiveSubscription()` matching `isActive()` semantics (grace included), `tenantId()` hook for per-tenant credentials (`CredentialResolverContract`).


### Webhooks
- One route (`POST /billing/webhooks/{gateway}`, path/middleware configurable), per-gateway signature validators (fail-closed when unconfigured), durable storage with pruning (`webhook.prune_after_days`), queued processing on a configurable connection/queue (`billing.queue.*`), custom acknowledgment bodies where a gateway requires one.
- Webhook processing retries on its own (`tries = 3`, 10s/60s/300s backoff) instead of inheriting a worker's `--tries=1`; the dedup claim commits together with your listeners, so a listener that throws rolls the claim back and the retry re-dispatches rather than dropping the outcome as a duplicate. Failures land in `billing_webhook_calls.exception`. Queued listeners need `after_commit` on their queue connection (see README "Horizon / Queue").
- Guarantees: a paid callback must match the payment's amount/currency (status polling applies the same check, so reconciliation can't accept what the webhook refused); a paid payment is never reverted by a late or out-of-order callback (an earlier decline, or a stale link's expiry arriving after a re-issued checkout was paid) — that delivery is ignored and logged; events are deduplicated per outcome (a re-delivery never double-fires, a declined-then-paid retry fires both); callbacks for unknown payments are ignored, not failed jobs.
- Events: `PaymentSucceeded`/`PaymentFailed`/`PaymentRefunded`/`PaymentCanceled`, `SubscriptionCreated`/`Renewed`/`PaymentFailed`/`Cancelled`/`Paused`/`Resumed`, `TrialWillEnd`, `PaymentMethodAttached`/`Detached`, `UsageLimitReached`, `CheckoutReturned`, `PaymentLinkOpened`.
- `billing:reconcile-pending-payments` (every 15 min) polls gateways for payments whose webhook never arrived — same events, same dedup, so a late webhook can't double-dispatch.

### Money
- Every amount is an integer in minor units; `Money::fromDecimal()`/`toDecimal()` bridge `decimal` columns safely. Two-decimal currencies only, by design.

### Setup & docs
- Octane-compatible (Swoole/RoadRunner/FrankenPHP): no per-request state survives in memory between requests — gateway instances and credentials (incl. per-tenant) are resolved per call.
- Database-agnostic: MySQL/MariaDB, PostgreSQL and SQLite are all supported — webhook callbacks carrying a foreign (non-UUID) reference are safely ignored and duplicate-delivery handling works identically on every driver; the test suite runs against SQLite and PostgreSQL.
- Migrations are published in groups (`billing-migrations-core` / `-subscriptions` / `-payment-methods`), re-publish-safe; morph id columns are strings (int and UUID billables/payables both fit).
- The schedule is off by default (`billing.schedule.enabled`); commands are idempotent and safe to re-register at any cadence.
- Docs: README (uk/en), `docs/use-cases.md` (end-to-end system designs), `docs/writing-a-gateway.md` (driver authoring), `docs/architecture.md` (internals), `docs/webhook-testing.md` (manual testing, tunnels).
