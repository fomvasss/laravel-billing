# Stripe

Stripe (`api.stripe.com/v1`, no SDK), gateway name `stripe`. Driver `Gateways\Stripe\StripeGateway`. Every request sends `Stripe-Version: 2026-08-26.dahlia` (`StripeGateway::API_VERSION`).

## Credentials

| Key | Env | Default | Meaning |
|---|---|---|---|
| `secret_key` | `STRIPE_SECRET_KEY` | — | `sk_...` |
| `webhook_secret` | `STRIPE_WEBHOOK_SECRET` | — | `whsec_...` of the webhook endpoint |
| `proration_behavior` | `STRIPE_PRORATION_BEHAVIOR` | `create_prorations` | How a subscription plan swap is billed: `create_prorations`, `always_invoice`, `none` |

Currencies: Stripe's presentment currencies minus the zero- and three-decimal ones and ISK; UAH works.

## Webhook endpoint

Stripe delivers only to endpoints registered in advance. The package registers one:

```bash
php artisan billing:stripe-register-webhook          # create, or update the events of an existing one
php artisan billing:stripe-register-webhook --fresh  # delete and re-create (new secret)
```

- **First run** creates the endpoint for `route('billing.webhook', 'stripe')` with the pinned API version and prints the signing secret — shown **only now**, Stripe never returns it again. Put it in `STRIPE_WEBHOOK_SECRET`.
- **Re-run** on an existing endpoint for the same URL updates its event list in place; the secret stays. It warns and exits 1 if the endpoint renders another API version — only `--fresh` changes that.
- **A new domain or path** is a new URL: the command creates a new endpoint (and a new secret) and leaves the old one in place — delete it in the dashboard.
- Options: `--url=` override, `--gateway=` for a second account, `--tenant=` for a tenant's own account (adds `?tenant=`).

Events subscribed: `checkout.session.completed`, `checkout.session.expired`, `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`, `invoice.paid`, `customer.subscription.created`, `.updated`, `.deleted`, `.paused`, `.resumed`, `.trial_will_end`.

Without the secret every webhook is rejected (fail-closed). Signature: HMAC-SHA256 of `{t}.{body}`, 5-minute tolerance.

## Checkout

`charge()` creates a Checkout Session in `payment` mode: `line_items` from the receipt items (or one line), `success_url`/`cancel_url` from the two return slots, `client_reference_id` and `metadata.payment_id` on the session and its PaymentIntent, `locale`, and `customer_email` (or, with `saveCard`, a per-billable Stripe `customer` and `setup_future_usage: off_session`). `external_id` starts as the session id (`cs_...`) and becomes the PaymentIntent (`pi_...`) once paid. `payment_url_expires_at` is the session's own `expires_at`.

| Event | Result |
|---|---|
| `checkout.session.completed` with `payment_status` `paid` | `paid` (amount/currency checked) |
| `checkout.session.expired` | `canceled` |
| `payment_intent.succeeded` | `paid` |
| `payment_intent.payment_failed` | `failed` — only for off-session intents; inside a live Checkout the customer may still retry another card |
| `charge.refunded` | A refund, from `amount_refunded` |

The fee isn't in Stripe's webhooks — `payments.fee` stays `null`.

## Saved cards

- Through Checkout: `saveCard: true` — after payment the driver fetches the PaymentIntent's payment method and saves it if it was attached to the customer, and makes it the customer's default on Stripe.
- Without a charge: `createCustomer($billable)`, a SetupIntent on your frontend, then `attachPaymentMethod($billable, ['payment_method_id' => 'pm_...'])`.
- Off-session: a PaymentIntent with `off_session`/`confirm` and the idempotency key `charge-{payment id}`. A `card_error` (decline, `authentication_required`) is returned in `raw`, not thrown; the outcome arrives as `payment_intent.*`. Off-session PaymentIntents have no basket.
- `expires_at` comes from the card's expiry month.
- `detachPaymentMethod()` detaches it on Stripe too.

## Refunds

`POST /refunds` on the PaymentIntent with a fresh idempotency key per call (two deliberate partial refunds of the same amount both go through). `failed`/`canceled` → `BillingException`. The refund row's `external_id` is the refund id (`re_...`). Dashboard refunds and disputes arrive as `charge.refunded`.

## Subscriptions (Stripe Billing)

Stripe supports both models: `charge()` with `saveCard` keeps a subscription package-managed; `Billing::startSubscription()` hands it to Stripe — a Checkout Session in `subscription` mode with an inline price (no catalog) and `subscription_data.metadata` carrying our ids.

- The checkout links the row; `invoice.paid` (amount > 0) is the proof of a paid period (the latest line period end). The first invoice only lends its PaymentIntent to the checkout payment; every later invoice becomes a new `Payment` row.
- `customer.subscription.*` events re-fetch the subscription (Stripe doesn't order deliveries) and sync it. `unpaid` reads as `past_due`, `incomplete_expired` as `canceled`. Cancellation is read from `cancel_at`.
- `cancel()` → `cancel_at_period_end` or `DELETE`; `swapPlan()` replaces the item's inline price (a product created by Checkout is inactive and can't take new prices, so the first swap creates an active product with the same name); `pause()`/`resume()` → `NotSupportedException`.
- Trials: `trial_days` and a first payment of `amount` 0; `trial_will_end` → `TrialWillEnd` (`notice` null).
- Dunning (Smart Retries) is configured in the Stripe dashboard.
- Not supported: metered prices, minute/hour intervals.

See [Provider-managed subscriptions](../provider-managed.md).

## Health

`GET /v1/balance` — reports `livemode: yes` or `no (test)`.
