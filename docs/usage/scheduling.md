# Scheduled commands

Off by default — they move money and subscription state, so a fresh install runs nothing until you opt in:

```env
BILLING_SCHEDULE_ENABLED=true
```

The commands are then registered with Laravel's scheduler, which still needs the standard cron entry (`* * * * * php artisan schedule:run`).

| Command | Cadence | What it does |
|---|---|---|
| `billing:process-recurring-charges` | every minute, `withoutOverlapping` | Finalizes due cancellations, charges due package-managed subscriptions, see [Renewals](renewals.md) |
| `billing:reconcile-pending-payments` | every 15 minutes, `withoutOverlapping` | Polls payments stuck `pending`, see below |
| `billing:expire-trials` | hourly | `TrialWillEnd` reminders, then `trialing` → `ended` with `TrialEnded`, see [Trials](trials.md) |
| `billing:send-period-notices` | hourly | `SubscriptionPeriodEnding` before a paid period ends (only if `period_ending_notices` is set) |
| `billing:expire-pauses` | hourly | Resumes `paused` subscriptions whose `pause_ends_at` passed (`SubscriptionResumed`) |
| `billing:reset-usage-quotas` | hourly | Resets quotas with their own cycle, see [Usage and quotas](usage-quotas.md) |
| `model:prune` for `BillingWebhookCall` | daily | Deletes webhook calls older than `webhook.prune_after_days` |

None of the housekeeping commands gates access — `isActive()` reads the row's own dates. A lagging run delays only the status write and the event. The exception is the quota reset: until it runs, the customer keeps hitting "quota exhausted".

Every command skips provider-managed subscriptions, except `reset-usage-quotas` and the trial reminders for providers that don't send their own (Paddle).

## `billing:reconcile-pending-payments`

The fallback for a lost webhook, and for gateway statuses that never get one (an expired checkout). Looks at `pending` payments not updated for more than `reconcile_after_minutes` (60). The age runs from `updated_at`, so a checkout re-issued through the pay link counts as fresh:

- `gateway` null — skipped (manual payment);
- gateway no longer registered — left alone;
- a subscription payment with neither `external_id` nor `payment_url` — a renewal whose initiation never got a reference — written off as `canceled`;
- the driver implements `ChecksPaymentStatus` — the gateway is polled (a Monobank, Stripe or Paddle payment without `external_id`, never charged yet, is skipped) and the outcome goes through the same dedup as webhooks (`paid`, `failed`, `canceled`; non-terminal states stay pending). Paddle also cancels an open transaction whose link TTL has passed, and polls pending Paddle refunds;
- no status polling (the `fake` gateway, custom drivers) — written off as `canceled` (`PaymentCanceled`), unless its `payment_url_expires_at` is still in the future.

One failing payment is reported and skipped, never blocks the rest.

## Custom cadence

The built-in schedule is a default. Turn it off and register the commands yourself — they are idempotent (pending-renewal guard, `next_retry_at`, shared dedup):

```php
// BILLING_SCHEDULE_ENABLED=false, then in routes/console.php:
Schedule::command('billing:process-recurring-charges')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
Schedule::command('billing:reconcile-pending-payments')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('billing:expire-trials')->everyMinute();
Schedule::command('billing:send-period-notices')->hourly();
Schedule::command('billing:expire-pauses')->everyMinute();
Schedule::command('billing:reset-usage-quotas')->hourly();
Schedule::command('model:prune', ['--model' => [\Fomvasss\Billing\Webhooks\BillingWebhookCall::class]])->daily();
```

Keep `withoutOverlapping()` on the money-moving commands, and add `onOneServer()` when the scheduler runs on several servers. Renewals are additionally serialized by a row lock per subscription, so even a manual run next to the scheduled one can't charge twice.

Command options — [Artisan commands](../reference/commands.md).
