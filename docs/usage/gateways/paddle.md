# Paddle

Paddle Billing (not Classic), gateway name `paddle`. Driver `Gateways\Paddle\PaddleGateway`. A `pdl_sdbx_` API key talks to the sandbox API, any other to live.

Paddle is a Merchant of Record, and that shapes the driver:

- no off-session charge outside a Paddle subscription — no saved cards;
- no Paddle-hosted checkout for the web — the payment link opens a page on **your** approved domain that loads Paddle.js;
- prices and products travel inline in each transaction — nothing to create in Paddle's catalog.

## Credentials

| Key | Env | Default | Meaning |
|---|---|---|---|
| `api_key` | `PADDLE_API_KEY` | — | `pdl_live_apikey_...` / `pdl_sdbx_apikey_...` |
| `client_token` | `PADDLE_CLIENT_TOKEN` | — | Client-side token for Paddle.js (`live_...` / `test_...`; `test_` switches the page to sandbox) |
| `checkout_url` | `PADDLE_CHECKOUT_URL` | — | Optional: this site's checkout page instead of the account's default payment link |
| `webhook_secret` | `PADDLE_WEBHOOK_SECRET` | — | Notification destination secret (`pdl_ntfset_...`) |
| `tax_category` | `PADDLE_TAX_CATEGORY` | `standard` | Tax category of inline products — must be enabled on your account |
| `proration_billing_mode` | `PADDLE_PRORATION_BILLING_MODE` | `prorated_immediately` | How a subscription plan swap is billed: `prorated_immediately`, `prorated_next_billing_period`, `full_immediately`, `full_next_billing_period`, `do_not_bill` |
| `link_ttl_minutes` | `PADDLE_LINK_TTL_MINUTES` | `1440` | How long a checkout stays open before the package cancels it |

Currencies: the two-decimal currencies Paddle accepts (UAH included); Paddle has per-currency minimums (UAH 29.00).

## One-time setup

1. Register the notification destination — safe to re-run, Paddle returns the secret on every read:

   ```bash
   php artisan billing:paddle-register-webhook   # prints PADDLE_WEBHOOK_SECRET
   ```

   Options: `--url=`, `--gateway=`, `--tenant=`. Re-run after upgrades that add events. Events: `transaction.completed`, `transaction.canceled`, `adjustment.created`, `adjustment.updated`, `subscription.created`, `.updated`, `.activated`, `.trialing`, `.past_due`, `.paused`, `.resumed`, `.canceled`.
2. **Checkout → Checkout settings → Default payment link**: `https://your-domain/billing/paddle/checkout`. Paddle refuses to create a transaction without one, and sends customers there to update a subscription's card.
3. **Checkout → Website approval**: add your domain before going live.

Signature: `Paddle-Signature: ts=...;h1=...`, HMAC-SHA256 of `{ts}:{body}`, 5-minute tolerance.

## Checkout page

A transaction's checkout URL is the default payment link plus `?_ptxn=txn_...`. The package page (`billing.paddle.checkout`) finds the payment by that transaction, loads Paddle.js with the client token of the gateway name it was charged through, and opens the overlay with the payment's success URL and locale. Closing the overlay without paying sends the customer to the **fail** return URL. A transaction that isn't one of your payments (a card update) just opens the checkout. Without a client token the page answers 404.

`PADDLE_CHECKOUT_URL` sends transactions to a specific site's page instead (several sites, staging next to production). Paddle refuses an unapproved domain even in the sandbox — that's why it is opt-in. The default payment link stays required.

## Payments

`charge()` creates a transaction: one item per receipt line (or one line), quantity pinned so the customer can't change it, `custom_data.payment_id`. It first cancels the payment's previous open transaction — Paddle transactions never expire, and two live links could both be paid. `external_id` = `txn_...`.

| Event | Result |
|---|---|
| `transaction.completed` | `paid`, fee from `details.totals.fee`. The amount check compares the issued unit prices × quantity (tax may be inside or on top) |
| `transaction.canceled` | `canceled` — unless it is an earlier transaction the driver itself canceled on re-issue |
| `transaction.payment_failed` | Not handled — the customer is still in the checkout |

Status polling also cancels a `draft`/`ready` transaction whose link TTL has passed.

## Refunds

Adjustments, usually awaiting Paddle's approval — the refund row is `pending` until `adjustment.updated` approves (`paid`, `PaymentRefunded`) or rejects it (`failed`, log warning, no event). A full refund is `type: full`; a partial one is scaled to the tax-inclusive total and spread over the transaction lines. Dashboard refunds are recorded once approved; chargebacks are only logged. See [Refunds](../refunds.md#paddle-refunds-that-wait-for-approval).

## Subscriptions

Provider-managed only, through `Billing::startSubscription()` — see [Provider-managed subscriptions](../provider-managed.md).

- The paid period comes only from `transaction.completed` (`billing_period.ends_at`), never from `subscription.*` — Paddle moves its period before collecting.
- A transaction Paddle generates for the subscription (renewal, proration, resume) becomes a new `Payment` (amount = grand total), even though Paddle copies the first payment's `custom_data` onto it.
- `cancel()`, `pause($until)` (from the end of the paid period, `resume_at`), `resume()`, `swapPlan()` go to Paddle; a refusal is a `BillingException` with Paddle's code (e.g. a prorated charge below the minimum).
- Trials: `trial_days`, a first payment of `amount` 0; `TrialWillEnd` comes from `billing:expire-trials`; a swap during a trial is always `do_not_bill`.
- Not supported: metered prices, minute/hour intervals.

> [!WARNING]
> Don't let a Paddle payment activate a package-managed subscription (a `charge()` against an `incomplete` row): the driver can't charge off-session, so `process-recurring-charges` skips it forever and the row stays `active` with access.

## Health

`GET /event-types` — reports `live` or `sandbox`.
