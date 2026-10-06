# Artisan commands

## billing:process-recurring-charges

```bash
php artisan billing:process-recurring-charges
```

Finalizes due period-end cancellations, then initiates renewal charges for due package-managed subscriptions. See [Renewals and dunning](../usage/renewals.md). Scheduled every minute.

## billing:reconcile-pending-payments

```bash
php artisan billing:reconcile-pending-payments
```

Polls (or writes off) payments `pending` longer than `reconcile_after_minutes`. See [Scheduled commands](../usage/scheduling.md#billingreconcile-pending-payments). Scheduled every 15 minutes.

## billing:expire-trials

Fires `TrialWillEnd` reminders, then moves expired package-managed trials to `ended` (`TrialEnded`). Hourly.

## billing:send-period-notices

Fires `SubscriptionPeriodEnding` per `period_ending_notices`. Hourly.

## billing:expire-pauses

Resumes `paused` subscriptions whose `pause_ends_at` passed. Hourly.

## billing:reset-usage-quotas

Resets usage of prices with their own quota cycle. Hourly.

## billing:health

```bash
php artisan billing:health {gateway?}
```

Probes one gateway, or every registered health-capable gateway, and prints a table (gateway, status, latency, detail). Exit code 1 if any is down — an unconfigured built-in counts as down. An unknown name throws.

## billing:stripe-register-webhook

```bash
php artisan billing:stripe-register-webhook [--url=] [--gateway=stripe] [--tenant=] [--fresh]
```

| Option | Meaning |
|---|---|
| `--url` | Endpoint URL; default `route('billing.webhook', <gateway>)` (+ `?tenant=`) |
| `--gateway` | Gateway name whose `secret_key` to use (a second Stripe account) |
| `--tenant` | Use that tenant's credentials and add `?tenant=` to the URL |
| `--fresh` | Delete existing endpoints for this URL and re-create (new secret) |

Without `--fresh` an existing endpoint for the URL gets its events updated (secret unchanged; exits 1 with a warning if its API version differs from the driver's). Otherwise creates the endpoint with the pinned API version and prints the signing secret once. See [Stripe](../usage/gateways/stripe.md#webhook-endpoint).

## billing:paddle-register-webhook

```bash
php artisan billing:paddle-register-webhook [--url=] [--gateway=paddle] [--tenant=]
```

Creates the notification destination, or updates the events of an existing one for the same URL, and prints its secret (Paddle returns it on every read). See [Paddle](../usage/gateways/paddle.md#one-time-setup).

## model:prune

Registered daily for `Fomvasss\Billing\Webhooks\BillingWebhookCall` when the schedule is enabled:

```bash
php artisan model:prune --model="Fomvasss\Billing\Webhooks\BillingWebhookCall"
```
