# Provider-managed subscriptions

Some gateways can run the whole subscription lifecycle themselves — renewals, retries, trial conversion, cancellation. Stripe (Stripe Billing) and Paddle do, through the built-in drivers. The package then mirrors what the provider reports instead of charging anything itself.

| | Package-managed | Provider-managed |
|---|---|---|
| Started with | `charge()` + a saved card | `Billing::startSubscription()` |
| Renewals, retries | `billing:process-recurring-charges` | The provider |
| `subscriptions.external_id` | `null` | The provider's subscription id |
| `cancel()`/`pause()`/`resume()`/`swapPlan()` | Local row | Forwarded to the provider |
| Gateways | Monobank, LiqPay, WayForPay, Hutko, Stripe | Stripe, Paddle |

The split is per **subscription**, not per gateway: on Stripe the same merchant can run B2C plans through Stripe Billing and B2B deals package-managed.

## Ownership marker

`external_id` on a subscription is not "some reference" — non-null means provider-managed (`isProviderManaged()`). Then:

- `process-recurring-charges`, cancellation finalizing, trial expiry, period notices and `expire-pauses` skip the row; so does the listener that advances periods and runs dunning on `PaymentSucceeded`/`PaymentFailed`;
- trial **reminders** still go out for a provider that doesn't send its own (Paddle) — only the reminder marker is written;
- `reset-usage-quotas` still resets the counter — it is local bookkeeping;
- `cancel()`, `pause()`, `resume()` and `swapPlan()` call the driver (`ManagesProviderSubscriptions`); a driver that can't throws `NotSupportedException` — the local row alone is never changed.

Only the driver writes `external_id`. Set it by hand and you tell the package "hands off".

## Starting one

Create the subscription as `incomplete` **with its gateway**, and its first payment with the subscription as payable:

```php
$subscription = Subscription::create([
    'status' => SubscriptionStatus::Incomplete,
    'gateway' => 'paddle',
    'price_id' => $price->id,
    'billable_type' => $organization->getMorphClass(),
    'billable_id' => $organization->id,
]);

$payment = Payment::create([
    'gateway' => 'paddle',
    'amount' => $price->amount, // × qty for a licensed price; 0 for a trial
    'currency' => $price->currency,
    'payable_type' => $subscription->getMorphClass(),
    'payable_id' => $subscription->id,
    'billable_type' => $organization->getMorphClass(),
    'billable_id' => $organization->id,
]);

return redirect(Billing::startSubscription($payment)->url);
```

`startSubscription()` does the same bookkeeping as `charge()` and throws `BillingException` if the payable isn't a `Subscription`, `NotSupportedException` if the gateway can't start provider subscriptions.

Requirements of both built-in drivers:

- the payment amount must split evenly over `qty` (the unit price is `amount / qty`), in the price's resolved currency;
- interval `day`, `week`, `month` or `year` — `minute`/`hour` throw `NotSupportedException`;
- `flat` or `licensed` — `metered` throws `NotSupportedException`;
- a price with `trial_days` needs a first payment of `amount` 0, see [Trials](#trials).

Prices and products travel inline — nothing has to exist in the provider's catalog.

## How the row follows the provider

The row is linked when the provider's webhook reports the new subscription, and from then on every state is written in one place — `Subscription::applyProviderSnapshot()` — from the driver's webhooks or from the provider's answer to a forwarded call. Events come from what changed:

| Change | Event |
|---|---|
| First link (`external_id` was null) | `SubscriptionCreated` |
| Paid period moved forward on an `active` row | `SubscriptionRenewed` (with `previousStatus`) |
| Into `past_due` | `SubscriptionPaymentFailed`; plus `SubscriptionAccessSuspended` without grace access |
| Into `paused` / out of `paused` | `SubscriptionPaused` / `SubscriptionResumed` |
| Into `canceled` | `SubscriptionCancelled` |

- Deliveries arrive out of order; a snapshot older than the last applied one (`provider_synced_at`) is dropped. Stripe snapshots are re-fetched current state.
- The paid period only moves forward, and only from proof of payment: Stripe `invoice.paid`, Paddle `transaction.completed` — both providers move their own period before collecting.
- Every renewal or plan-change charge is a **new** `Payment` row with the subscription as payable, `initiation` `automatic`; the checkout payment is never overwritten. Refund them with `Billing::refund()` as usual.
- The package's dunning fields (`recurring_attempts`, `grace_ends_at`, `next_retry_at`) are never touched.

## Access

`isActive()` softens two boundaries for these rows, because the provider decides and reports by webhook:

- **trial end** — a `trialing` row keeps access past `trial_ends_at` until the provider converts or cancels it;
- **`past_due`** — no grace window of ours: access follows `hasGraceAccess()` alone for as long as the provider keeps retrying.

## Managing it

```php
$subscription->cancel();                   // at the end of the paid period
$subscription->cancel(atPeriodEnd: false); // now
$subscription->pause(now()->addMonth());   // Paddle only
$subscription->resume();                   // Paddle only
$subscription->swapPlan($otherPrice);      // provider's proration rules
```

| | Stripe | Paddle |
|---|---|---|
| `cancel()` | `cancel_at_period_end`, or `DELETE` now | `effective_from: next_billing_period` / `immediately` |
| `pause($until)` | `NotSupportedException` (Stripe's pause is preview-only) | From the end of the paid period; `$until` → `resume_at` |
| `resume()` | `NotSupportedException` | A paused subscription resumes now (bills a new period); a scheduled pause is dropped |
| `swapPlan()` | Replaces the item's inline price; `proration_behavior` credential (default `create_prorations`) | Inline price on the same product; `proration_billing_mode` credential (default `prorated_immediately`); always `do_not_bill` while trialing |

A refusal surfaces as `BillingException` carrying the provider's error code — show it to whoever clicked. Paddle refuses, for example, a prorated charge under its minimum (29.00 UAH, 0.70 USD). The customer may also cancel on the provider's side (Paddle's emails, Stripe's portal) — the row follows and `SubscriptionCancelled` fires the same way.

Grandfathering differs: `swapPlan()` here bills per the proration setting instead of silently applying from the next renewal — set `do_not_bill` (Paddle) or `none` (Stripe) if that is what you want.

## Trials

A price with `trial_days` starts a card-required free trial: create the first payment with `amount` 0 (anything else throws `BillingException` before the checkout), the checkout stores the card and charges nothing. The row is `trialing` with the provider's `trial_ends_at` and no paid period. When the trial ends the provider charges the full price — a new `Payment` and `SubscriptionRenewed` with `previousStatus` `Trialing`.

- Stripe sends `customer.subscription.trial_will_end` → `TrialWillEnd` with `notice` null; the package adds no reminders of its own (the driver implements `ReportsTrialEnding`).
- Paddle has no such event: `billing:expire-trials` fires `TrialWillEnd` from the mirrored date, by `trial_ending_notices`.
- Neither offers a card-free trial through the package.

## Writing such a driver

See [Writing a gateway → Provider-managed subscriptions](../guides/writing-a-gateway.md#provider-managed-subscriptions).
