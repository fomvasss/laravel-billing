# Trials

## Starting a trial

A package-managed trial needs no gateway call and no card — just a `trialing` row:

```php
$subscription = Subscription::create([
    'status' => SubscriptionStatus::Trialing,
    'gateway' => null, // the first successful payment stamps its gateway
    'price_id' => $price->id,
    'billable_type' => $organization->getMorphClass(),
    'billable_id' => $organization->id,
]);
```

`trial_ends_at` is filled from the price's `trial_days` when the row is created as `trialing` without one. Pass `trial_ends_at` yourself to override. A `trialing` row with `trial_ends_at` null is an open-ended trial — `isActive()` stays true.

Access ends exactly at `trial_ends_at`, whether or not the scheduler has run.

## Reminders: `TrialWillEnd`

`billing:expire-trials` (hourly, needs the schedule) fires `TrialWillEnd($subscription, $notice)` at each `trial_ending_notices` interval before `trial_ends_at`:

```php
// config/billing.php
'trial_ending_notices' => ['7 days', '3 days', '1 day'], // default ['3 days']; int = minutes
```

- Each notice fires at most once per subscription (marker `trial_notices_sent`).
- If several become due in one run (a trial created mid-window, a scheduler outage), only the closest fires; the rest are marked sent.
- `$event->notice` is the config entry that fired (`'3 days'`) — word the message by it.
- Per price: `prices.trial_ending_notices` — `null` = global list, `[]` = no reminders, an array = its own cadence. A yearly plan and an hourly rental can coexist.
- Minute-scale notices (`'15 minutes'`) need the command to run more often than hourly — register your own cadence, see [Scheduled commands](scheduling.md#custom-cadence).

## Expiry: `TrialEnded`

The same command moves package-managed `trialing` rows past `trial_ends_at` to `ended` and fires `TrialEnded` for each — the hook for the "your free period is over" message. It never takes money.

## Converting

Converting is a payment against the subscription — there is no separate method:

```php
// keep the remaining free days: the period starts where the trial ends
$subscription->update(['current_period_ends_at' => $subscription->trial_ends_at]);

$payment = Payment::create([
    'gateway' => 'monobank',
    'amount' => $subscription->price->amount,
    'currency' => $subscription->price->currency,
    'payable_type' => $subscription->getMorphClass(),
    'payable_id' => $subscription->id,
    'billable_type' => $subscription->billable_type,
    'billable_id' => $subscription->billable_id,
]);

Billing::charge($payment, new ChargeOptions(saveCard: true));

return redirect($payment->payment_url);
```

`PaymentSucceeded` flips the row to `active`, advances the period from `current_period_ends_at` (or from now if it is null), and fires `SubscriptionRenewed` with `previousStatus` `Trialing`. The saved card makes later renewals automatic.

A declined card during the trial changes nothing — dunning applies only to real renewals; the trial keeps running until it converts or expires. A trial that already became `ended` refuses the payment; create a new subscription row.

Only Stripe can collect a card during the trial without charging (SetupIntent + `attachPaymentMethod()`, see [Saved cards](saved-cards.md#saving-a-card-without-charging--stripe)); the conversion is then yours to make with `chargeWithMethod()`.

## Provider-managed trials

On Stripe and Paddle a price with `trial_days` started through `Billing::startSubscription()` is a card-required free trial run by the provider; the first payment must have `amount` 0. Stripe sends its own `trial_will_end` (mapped to `TrialWillEnd` with `notice` null); for Paddle, which has no such event, `billing:expire-trials` sends the local reminders from the mirrored `trial_ends_at`. The status is never ended locally. See [Provider-managed subscriptions](provider-managed.md#trials).
