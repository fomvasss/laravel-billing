# Real-world use cases

The README documents the package feature by feature; this document shows how the features compose into whole systems. Each case states the business problem, splits the design into *what the package owns* and *what your app owns*, and shows the glue code. The recurring theme is the package's core boundary: **it owns money movement (charges, webhooks, saved cards, renewal mechanics) and signals everything as events — your app owns products, entitlements and messaging.**

All examples assume the setup from the README (migrations published, a gateway configured, the schedule enabled).

---

## 1. SaaS: free trial, token quota, purchasable token packs

**The business:** on signup the customer gets 14 days free with 1,000 AI tokens. The paid plan is a flat monthly price with 4,000 tokens included. Extra token packs can be bought at any time; they never expire and remain usable even without an active subscription.

**Split:** subscription, trial, quota and all charging — package. The token *wallet* (purchased packs) — your app, as an append-only ledger. The package deliberately has no wallet concept: a generic one degrades to `id + balance` and fits nobody; a ledger on your side is ~30 lines and fits exactly.

### Package side

```php
$plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);

$price = $plan->prices()->create([
    'gateway' => 'monobank',
    'currency' => 'UAH',
    'amount' => 29900, // 299.00/month, flat — the price doesn't depend on usage
    'pricing_type' => PricingType::Flat,
    'interval' => Interval::Month,
    'interval_count' => 1,
    'included_units' => 4000, // ...but it carries a quota
]);

// signup — nobody knows yet how (or if) they'll pay
$subscription = Subscription::create([
    'status' => SubscriptionStatus::Trialing,
    'gateway' => null, // the first successful payment stamps its gateway automatically
    'price_id' => $price->id,
    'billable_type' => Organization::class,
    'billable_id' => $organization->id,
    'trial_ends_at' => now()->addDays(14),
]);
```

Conversion is just a payment against the subscription (see the README's trial recipe): `Billing::charge($payment, new ChargeOptions(saveCard: true))` → the listener flips it to `active`, the card is saved, renewals run themselves. On every successful renewal the quota resets to a fresh 4,000 automatically (any price with `included_units` does).

The trial's smaller quota is a one-line wrapper — a per-subscription quota override isn't package schema, it's your rule:

```php
public function tokenQuota(Subscription $subscription): float
{
    return $subscription->onTrial() ? 1000 : (float) $subscription->price->included_units;
}
```

### App side — the wallet ledger

```php
Schema::create('token_transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained();
    $table->bigInteger('delta'); // + purchase, − spend
    $table->string('reason', 40); // purchase | spend | adjustment
    $table->foreignUuid('payment_id')->nullable(); // the billing Payment that funded a purchase
    $table->json('meta')->nullable();
    $table->timestamps();

    $table->unique(['payment_id', 'reason']); // a retried webhook can't credit twice
});
```

Selling a pack is the README's "one-off purchase" recipe — a plain `Payment` whose `meta` says what it is, credited by a listener:

```php
$payment = Payment::create([
    ..., // status/type/gateway/amount/payable/billable as usual
    'meta' => ['product' => 'token_pack', 'tokens' => 1000],
]);
Billing::chargeWithMethod($payment, $organization->defaultPaymentMethod); // card is already saved

Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    if (($event->payment->meta['product'] ?? null) !== 'token_pack') {
        return;
    }

    TokenTransaction::firstOrCreate(
        ['payment_id' => $event->payment->id, 'reason' => 'purchase'],
        ['organization_id' => $event->payment->billable_id, 'delta' => $event->payment->meta['tokens']],
    );
});
```

One spend entry point enforces every business rule at once — subscription quota first, wallet for the overflow, wallet-only when no subscription is active:

```php
class TokenService
{
    public function spend(Organization $org, int $tokens, string $idempotencyKey): void
    {
        $subscription = $org->activeSubscription('pro');
        $fromQuota = min($tokens, max(0, (int) ($subscription?->remainingUsage() ?? 0)));

        if ($fromQuota > 0) {
            $subscription->reportUsage($fromQuota, $idempotencyKey); // package-side, idempotent
        }

        if ($rest = $tokens - $fromQuota) {
            $this->debitWallet($org, $rest, $idempotencyKey); // throws when the balance is short
        }
    }
}
```

`UsageLimitReached` (fires once when the quota is crossed) is your "buy a pack" call-to-action. Why a ledger and not a balance column: immutable `delta` rows give an audit trail (every credit points at its `Payment`), retry-safety, and a balance that is always recomputable as `SUM(delta)`.

---

## 2. Store: orders with fiscal receipts, expenses kept out of billing

**The business:** a shop takes order payments with itemised fiscal receipts; the owner also records expenses (supplier purchase for an order, delivery, ads) and wants per-order profit.

**Split:** order payments and fiscalization — package. Expenses — your own table. It's tempting to reuse the payments table with an `operation: income|expense` column (a pattern real shops use) — don't: in this package every `Payment` is *a customer's money for something* (`payable`/`billable` are NOT NULL, webhooks/reconciliation/refund guards are built on it), and an expense has no customer, no gateway and no webhook. One shared table would force an `operation = income` filter into every query the package makes — the exact tax the separate table avoids.

### Package side

```php
class Order extends Model implements Payable, HasReceiptItems
{
    public function receiptItems(): array
    {
        return $this->items->map(fn (OrderItem $item) => [
            'name' => $item->product->name,
            'qty' => $item->qty,
            'unitAmount' => $item->unit_price, // minor units
            'sku' => $item->product->sku,
        ])->all();
    }
}
```

`Billing::charge($payment)` picks the basket up automatically — Monobank/WayForPay/Stripe/Hutko fiscalize it as-is (LiqPay's catalog-id-based `rro_info` goes via `ChargeOptions::$raw`, see the README recipe). Fulfilment is a `PaymentSucceeded` listener.

### App side — expenses

```php
Schema::create('expenses', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('amount'); // positive, minor units — same money convention as billing
    $table->string('currency', 3)->default('UAH');
    $table->string('category', 100)->nullable();
    $table->string('description')->nullable();
    $table->date('spent_at');
    $table->json('meta')->nullable();
    $table->timestamps();
    $table->nullableMorphs('expensable'); // Order for per-order costs, null for general ones
    $table->foreignId('user_id')->nullable()->constrained(); // who recorded it
});
```

Per-order profit becomes two clean sums instead of sign-juggling inside one table:

```php
public function profit(): int
{
    return Payment::query()->forBillable($this->user)
            ->where('payable_type', $this->getMorphClass())->where('payable_id', $this->id)
            ->paid()->sum('amount')
        - $this->expenses()->sum('amount');
}
```

A combined "all money movement" report, if you need that screen, is a `UNION` in the report query — not a shared table.

---

## 3. Short-cycle billing: hourly parking / scooter rental

**The business:** a parking spot billed hourly; the customer's card is charged every hour until they stop.

**What makes it different:** every timing default designed for monthly plans is wrong at this scale — and each one is a config knob, not a code change.

```php
$price = $plan->prices()->create([
    'gateway' => 'monobank',
    'currency' => 'UAH',
    'amount' => 5000, // 50.00/hour
    'pricing_type' => PricingType::Flat,
    'interval' => Interval::Hour,
    'interval_count' => 1,
]);
```

- **Charging cadence** — already covered: `billing:process-recurring-charges` runs every minute by default, so a period ending at 13:23 is charged at 13:23–13:24.
- **Dunning** — the monthly defaults (3 retries, 24 h apart, 3-day grace) are absurd against a one-hour period; a failed hourly charge should end the rental:

```dotenv
BILLING_MAX_RECURRING_ATTEMPTS=1   # first failure → canceled, no past_due limbo
```

- **Trial/first-hour reminders** — minute-scale notices, and run the command more often than its daily default:

```php
'trial_ending_notices' => ['15 minutes'],
```
```php
// config/billing.php: 'schedule' => ['enabled' => false], then register your own cadence:
Schedule::command('billing:process-recurring-charges')->everyMinute()->withoutOverlapping();
Schedule::command('billing:reconcile-pending-payments')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('billing:expire-trials')->everyMinute();
```

- **Stopping the rental** is `cancel()`: `atPeriodEnd: false` frees the spot immediately, the default period-end variant lets the paid hour run out (finalized by the scheduler — which at this cadence reacts within a minute).
- **Reconciliation matters more here**: a lost webhook that a monthly plan wouldn't notice for an hour is a whole billing period for a rental — hence the five-minute reconcile above, with `BILLING_RECONCILE_AFTER_MINUTES` lowered to taste.

---

## 4. Tariff storefront: base plans, optional add-ons, hidden legacy prices

**The business:** the pricing page shows base plans to everyone; add-ons (AI module, extra channel) only to customers with an active base plan, and never the ones they already own; grandfathered customers keep old prices that new customers must not see.

**Split:** the package stores plans/prices and answers "who has what" (`hasActiveSubscription()`); *which tariffs to show to whom* is presentation — your controller over `Plan.meta`, which the package never reads:

```php
Plan::create(['code' => 'pro', 'name' => 'Pro', 'meta' => ['type' => 'base', 'sort' => 1]]);
Plan::create(['code' => 'ai-addon', 'name' => 'AI Addon', 'meta' => ['type' => 'addon', 'requires' => 'pro']]);
Plan::create(['code' => 'legacy-2024', 'name' => 'Pro 2024', 'meta' => ['type' => 'base', 'hidden' => true]]);
```

```php
public function tariffs(Request $request)
{
    $org = $request->user()->organization;

    $base = Plan::where('meta->type', 'base')
        ->where(fn ($q) => $q->whereNull('meta->hidden')->orWhere('meta->hidden', false))
        ->with(['prices' => fn ($q) => $q->where('is_active', true)->where('currency', $org->currency)])
        ->orderBy('meta->sort')
        ->get();

    $addons = $org->hasActiveSubscription('pro')
        ? Plan::where('meta->type', 'addon')->get()
            ->reject(fn (Plan $plan) => $org->hasActiveSubscription($plan->code)) // hide what's owned
        : collect();

    return compact('base', 'addons');
}
```

The supporting package mechanics: `Price.is_active = false` retires a price for new signups while existing subscriptions keep renewing on it; one plan holds prices per gateway/currency and `resolveChargeAmount()` picks the right one; `Subscription.billable_id` is not unique, so a base plan and several add-ons run as parallel subscriptions with independent lifecycles — cancelling one never touches the others.

### The pricing-page payload

The classic pricing table — name, tagline, feature bullets, a Monthly/Annual toggle, a "Current" badge — maps onto the same rows with nothing extra: presentation copy lives in `Plan.meta` (the package stores it, never reads it), the toggle is just two `Price` rows per plan with different `interval`s, and "current" is a comparison against `activeSubscription()`. The package is deliberately headless — this endpoint is the whole integration:

```php
public function plans(Request $request)
{
    $current = $request->user()->activeSubscription()?->price;

    return Plan::query()
        ->with(['prices' => fn ($q) => $q->where('is_active', true)->whereNull('gateway')])
        ->orderBy('meta->sort')
        ->get()
        ->map(fn (Plan $plan) => [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->meta['description'] ?? null, // "For growing teams"
            'features' => $plan->meta['features'] ?? [],          // ["10 seats", "Priority support"]
            'is_current' => $current?->plan_id === $plan->id,
            'prices' => $plan->prices->map(fn (Price $price) => [
                'interval' => $price->interval?->value,           // 'month' | 'year' → the toggle
                'amount' => Money::toDecimal($price->amount),     // "49.00"
                'currency' => $price->currency,
            ])->values(),
        ]);
}
```

---

## 5. Multi-currency storefront: price in USD, settle in UAH, track the gateway's cut

**The business:** prices are quoted and shown in USD (stable against inflation, familiar to the market), but the actual charge must go through in UAH — fiscalization only works in the settlement currency. After the payment the owner wants to know the *net* proceeds: what landed after the gateway's commission.

**Split:** the conversion moment, the recorded rate and the fee fact — package columns and drivers. The display currency, the rate source and any *own* commission policy — your app.

### The one rule that makes it simple

A `Payment` lives in **one currency — the one the money actually moves in**. USD on the site is presentation; the moment billing starts, you convert once, create the row in UAH, and record the conversion facts next to it:

```php
$usd = new Money($order->total_usd, 'USD');
$uah = app(CurrencyConverterContract::class)->convert($usd, 'UAH'); // your rate source behind the contract

$payment = Payment::create([
    'status' => PaymentStatus::Pending,
    'type' => PaymentType::Charge,
    'gateway' => 'monobank',
    'amount' => $uah->amount,
    'currency' => 'UAH',
    'converted_from_currency' => 'USD',
    'exchange_rate' => $uah->amount / $usd->amount,
    'exchange_rate_at' => now(),
    'payable_type' => Order::class,
    'payable_id' => $order->id,
    'billable_type' => User::class,
    'billable_id' => $order->user_id,
]);

Billing::charge($payment);
```

Everything downstream now agrees on one number: the checkout page shows the UAH sum, the webhook's amount verification checks the UAH sum, fiscalization receipts the UAH sum. The USD origin and the exact rate stay on the row — an argument about "what did we actually sell this for" is a query, not an archaeology dig. (For subscriptions, `resolveChargeAmount()` does this conversion and stamps the same columns automatically.)

### The gateway's cut

When the paid callback arrives, the driver also parses the gateway's commission into `payments.fee` where the gateway reports it (Monobank, LiqPay, WayForPay, Hutko — Stripe keeps fees on a separate API object, so it stays `null` there). `null` means "unknown", never a guessed zero; `$payment->netAmount()` gives `amount - fee` once the fee is known.

If the business prefers its *own* booked commission (a flat percent agreed with finance rather than the bank's exact cut), a `PaymentSucceeded` listener overwrites or fills the same column — it runs after the driver, so it can see what the bank reported and decide. The README's "Gateway fee and net amount" section has the listener.

### Reporting back in USD

The report wants USD, the rows are UAH — and both conversions you might want are already on the row: `amount / exchange_rate` re-derives the USD the customer was quoted, while converting `netAmount()` at *today's* rate answers "what is that worth now". Which one is correct depends on the question — which is exactly why the package stores the facts and doesn't pick for you.

---

## 6. The listener layer: "the package signals — your app decides"

Every consequential moment is an event, and the events deliberately carry *context*, not *decisions*. The patterns that keep coming up:

**Selective messaging.** `TrialWillEnd` fires for every trial; whether a given customer gets an email is your rule, one `if` deep, because the event reaches everything (`subscription → price → plan → meta → billable`):

```php
Event::listen(TrialWillEnd::class, function (TrialWillEnd $event) {
    if (($event->subscription->price?->plan?->meta['trial_reminders'] ?? true) === false) {
        return; // this plan opted out of reminders
    }

    Mail::to($event->subscription->billable)
        ->send(new TrialEndingMail($event->subscription, $event->notice)); // '3 days' vs '15 minutes' → different wording
});
```

**Sales/analytics signals with no payment weight.** `PaymentLinkOpened` (the permanent `billing.pay` link was visited) and `CheckoutReturned` (the browser came back from a gateway) prove nothing about money — which is exactly what makes them useful as behavioral signals: "opened the invoice twice this week, still unpaid" is a follow-up trigger, not a billing state.

**A state journal.** The subscription row keeps only its current status; if you want the history ("was past_due from the 3rd to the 5th"), one listener over the transition events writes it — the README's "Statuses and history" section has the worked example.

The inverse rule matters just as much: never *act on money* from the UX-side signals. Fulfilment, entitlements, wallet credits — only from `PaymentSucceeded`/`PaymentFailed`/`PaymentRefunded`, which come exclusively through the verified webhook/reconciliation pipeline.
