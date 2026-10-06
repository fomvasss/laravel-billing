# Production checklist

What to check before taking real money, and why each item matters.

## Webhooks and queue

- **`APP_URL` is the public HTTPS URL.** Every callback URL is built from it; a wrong one sends the gateway's webhooks nowhere.
- **A worker consumes the billing queue.** Webhooks are acknowledged and stored even when nothing processes them — payments then stay `pending` until reconciliation polls them an hour later. A dedicated queue (`BILLING_QUEUE=billing`) keeps a busy default queue from delaying "paid".
- **`after_commit` for queued listeners** of billing events, or they can run before the webhook transaction commits.
- **Listener side effects are idempotent.** The webhook job retries (3 tries); DB writes roll back with the dedup claim, emails and external API calls don't.
- **Stripe and Paddle endpoints are registered** for the production URL (`billing:stripe-register-webhook`, `billing:paddle-register-webhook`), and the Paddle default payment link and domain approval are set.
- **Webhook middleware** doesn't block gateways — no basic auth, no IP allowlist that misses the gateway's addresses, no `web` group.

## Scheduler

- **`BILLING_SCHEDULE_ENABLED=true`** and the system cron runs `schedule:run`. Without it nothing renews, period-end cancellations never finalize, lost webhooks are never reconciled, and webhook calls are never pruned.
- **`onOneServer()`** if the scheduler runs on several servers — register the commands yourself (see [Scheduled commands](../usage/scheduling.md#custom-cadence)); the built-in schedule uses only `withoutOverlapping()`.

## Cache

The cache store backs the refund lock, the pay-link re-issue lock, `withoutOverlapping()`, LiqPay's checkout forms, Paddle's checkout settings, Monobank's public key and usage idempotency keys. Use a shared store (`redis`, `memcached`, `database`) when you run more than one app server — `file` locks only within one machine, `array` protects nothing.

## Configuration

- **Return URLs** are set (`BILLING_RETURN_URL_SUCCESS`, and `_FAILED` for Stripe/Paddle), and the success page reads the real payment status — on Monobank, LiqPay, WayForPay and Hutko a declined customer lands there too.
- **`BILLING_DEBUG=false`** — driver logs may contain request data.
- **Credentials are live**, not test: LiqPay `sandbox` status counts as paid; a Paddle `pdl_sdbx_` key talks to the sandbox.
- **Dunning defaults fit your periods** — the 6 h / 24 h / 48 h ladder and 3-day grace are for monthly plans.
- **`webhook.prune_after_days`** stays above the longest gateway retry (WayForPay: 4 days).

## Payments

- **Fulfil from `PaymentSucceeded`**, never from the return page or `CheckoutReturned`.
- **Watch the log** for `paid webhook amount/currency mismatch` (a paid callback left pending for review) and `a reversal was reported for a payment but not recorded` (a refund to record by hand).
- **Bind `ReissueChargeOptionsContract`** if a re-issued checkout must save the card or carry a fiscal basket.
- **Bind `RenewalChargeOptionsContract`** (or enable `BILLING_RENEWAL_RECEIPT_ITEMS`) if renewals must be fiscalized.
- **Monitor gateways** with `billing:health <gateway>` — name the gateways you use; without arguments unconfigured built-ins report DOWN.

## Invoices

- Seller name set before enabling `auto_invoice`/`auto_receipt` — nothing is issued without it, and an issued document never changes.
- `pdf_middleware` or your own route if a forwarded signed link must not open the document.
- `barryvdh/laravel-dompdf` installed (or a renderer bound) on every server that renders PDFs.
