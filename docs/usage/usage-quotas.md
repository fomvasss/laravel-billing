# Usage and quotas

`subscriptions.current_usage` is a counter, and `prices.included_units` an optional allowance per period. Both are independent of `pricing_type`: a `flat` price can carry a quota ("4 000 AI tokens a month, same price either way"), a `metered` price bills `amount × current_usage`.

## Reporting usage

```php
$subscription->reportUsage(1500, idempotencyKey: "ai-run:{$run->id}");

$subscription->remainingUsage(); // ?float — included_units - current_usage, null without a quota
```

- `reportUsage()` only adds; a negative quantity throws `InvalidArgumentException`.
- The idempotency key is remembered in the **cache** for 24 hours (`Cache::add`), so a retried call with the same key within a day is ignored. It is not a durable ledger: a later retry, or a cache that doesn't span your servers, counts twice.
- `UsageLimitReached($subscription)` fires once when cumulative usage crosses `included_units` — your cue to block, notify, or charge an overage with `chargeWithMethod()`. It fires again only after the counter was reset.

## Reset with the billing period

On a paid renewal `current_usage` goes back to 0 when the price is `metered` or has `included_units` — a fresh period, a fresh allowance. Quota-less `flat`/`licensed` usage is left alone: there it's a counter your app owns.

## A quota cycle of its own

"Pay for a year, get 10 000 units every month":

```php
$plan->prices()->create([
    // ...
    'interval' => Interval::Year,
    'interval_count' => 1,
    'included_units' => 10000,
    'quota_interval' => Interval::Month,
    'quota_interval_count' => 1,
]);
```

`subscriptions.quota_period_ends_at` holds the next boundary — set when the subscription is created (trial included). `billing:reset-usage-quotas` (hourly) zeroes `current_usage` once it passes, moves the boundary forward and fires `SubscriptionQuotaReset`.

- `quota_interval` is honoured only together with `included_units`.
- Unused allowance expires: after three missed cycles (a scheduler outage) the customer gets one fresh allowance, not three.
- A paid renewal restarts the cycle from that moment.
- Provider-managed subscriptions are reset too — unlike every other scheduled command, a quota reset touches no gateway and no money.
- Only `trialing`, `active` and `past_due` rows are reset. A paused one keeps its stale boundary and gets its allowance on the first reset run after it resumes.

## Metered billing

For a `metered` price the renewal charges `amount × current_usage` (rounded to a minor unit) and the counter resets on payment. A period with zero usage advances without a gateway call. Metered prices are package-managed only — Stripe and Paddle subscriptions refuse them.

```php
$price = $plan->prices()->create([
    'gateway' => 'stripe',
    'currency' => 'UAH',
    'amount' => 350, // per minute
    'pricing_type' => PricingType::Metered,
    'interval' => Interval::Month,
    'unit_label' => 'minute',
]);

$subscription->reportUsage($ride->minutes, idempotencyKey: "ride:{$ride->id}");
```

See also [Use cases → SaaS with a token quota](../guides/use-cases.md#1-saas-free-trial-token-quota-purchasable-token-packs) for a quota plus a wallet of purchased packs.
