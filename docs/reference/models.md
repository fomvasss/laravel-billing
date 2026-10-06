# Models

All in `Fomvasss\Billing\Models` (the webhook call in `Fomvasss\Billing\Webhooks`), UUID v7 keys (`HasUuids`), `$guarded = ['id']`. Columns — [Database tables](database.md).

## Payment

Table `billing_payments`, soft deletes. `paid_at` is stamped when `status` becomes `paid` and cleared when it leaves; `tenant_id` is derived from the billable on create when empty.

| Member | Returns | Description |
|---|---|---|
| `payable()`, `billable()` | `MorphTo` | |
| `parentPayment()` | `BelongsTo` | The charge a refund row belongs to |
| `refunds()` | `HasMany` | Child refund rows |
| `isPaid()`, `isPending()`, `isFailed()` | `bool` | Status checks |
| `isRefund()` | `bool` | `type` is `refund` |
| `isManual()`, `isAutomatic()` | `bool` | `initiation`; both false when null |
| `transitionTo(PaymentStatus $status, array $attributes = [])` | `bool` | Status update that refuses to leave `paid` (logs, returns false) |
| `money()` | `Money` | `amount` + `currency` |
| `refundedAmount()` / `refundedMoney()` | `int` / `Money` | Sum of `paid` refund rows, trashed included |
| `refundableRemainder()` / `refundableRemainderMoney()` | `int` / `Money` | `amount` minus `paid` and `pending` refunds |
| `netAmount()` / `netMoney()` | `?int` / `?Money` | `amount - fee`; null while `fee` is unknown |
| `feeMoney()` | `?Money` | |
| `hasActivePaymentUrl()` | `bool` | `payment_url` set and not expired (no expiry = alive) |
| `Payment::recordRefundOf(Payment $charge, Money $money, ?string $externalId = null, array $raw = [], PaymentStatus $status = Paid)` | `Payment` | Builds a refund row (used by the package) |
| `Payment::findByNumber(string $number)` | `?Payment` | By `number` |
| scopes `paid()`, `pending()`, `forBillable(Model $billable)` | | |

## Subscription

Table `billing_subscriptions`. On create: `trial_ends_at` from the price's `trial_days` for a `trialing` row without one; `quota_period_ends_at` for a price with its own quota cycle; `tenant_id` from the billable.

| Member | Returns | Description |
|---|---|---|
| `billable()`, `price()` | relations | |
| `isActive()` | `bool` | Entitled right now, see [Access](../usage/subscriptions.md#access-isactive) |
| `onTrial()` | `bool` | `trialing` and `trial_ends_at` null or future |
| `onGracePeriod()` | `bool` | `grace_ends_at` in the future |
| `isCanceled()` | `bool` | |
| `isCancelling()` | `bool` | `cancels_at` in the future, not yet canceled |
| `hasGraceAccess()` | `bool` | `price.grace_access` ?? config |
| `isProviderManaged()` | `bool` | `external_id` not null |
| `nextPeriodEnd()` | `?Carbon` | Where a successful renewal moves the period; null for a price without interval |
| `nextQuotaPeriodEnd(?Carbon $base = null)` | `?Carbon` | Next quota boundary for an own-cycle price |
| `retryIntervals()`, `periodEndingNotices()` | `list` | Effective lists (price override or config) |
| `reportUsage(float $quantity, ?string $idempotencyKey = null)` | `void` | Adds usage, fires `UsageLimitReached` on crossing the quota |
| `remainingUsage()` | `?float` | Null without `included_units` |
| `resetUsageQuota()` | `bool` | Used by `billing:reset-usage-quotas` |
| `pause(?DateTimeInterface $until = null)`, `resume()` | `void` | Local, or forwarded for provider-managed |
| `cancel(bool $atPeriodEnd = true)` | `void` | Local, or forwarded |
| `swapPlan(Price $newPrice)` | `void` | Local, or forwarded |
| `markCanceled(array $attributes = [])` | `void` | The one way into `canceled`; fires `SubscriptionCancelled` |
| `recordRenewalSuccess(?string $gateway = null)` | `bool` | Advances the period; false (logged) for canceled/ended/paused |
| `recordRenewalFailure()` | `void` | One dunning step |
| `applyProviderSnapshot(SubscriptionSnapshot $snapshot)` | `bool` | Writes provider state, fires events from the diff; false for a stale snapshot |
| scopes `active()`, `forBillable(Model $billable)` | | `active()` mirrors `isActive()` in SQL |

## Plan

Table `billing_plans`: `code`, `name`, `meta` (array). `prices()` — `HasMany`.

## Price

Table `billing_prices`. Casts: `interval`/`quota_interval` → `Interval`, `pricing_type` → `PricingType`, notice/retry lists → arrays, `included_units` → float.

| Member | Returns | Description |
|---|---|---|
| `plan()`, `subscriptions()` | relations | |
| `money()` | `Money` | |
| `hasOwnQuotaCycle()` | `bool` | `quota_interval` and `included_units` both set |
| `chargeMultiplier(Subscription $subscription)` | `int\|float` | 1 / `qty` / `current_usage` by pricing type |

## PaymentMethod

Table `billing_payment_methods`: `gateway`, `type` (`card`), `brand`, `last4`, `expires_at`, `is_default`, `external_customer_id`, `external_id` (the token), billable morph, `tenant_id`. `billable()` — `MorphTo`.

## Invoice

Table `billing_invoices`. Issue through the facade, not `create()`.

| Member | Returns | Description |
|---|---|---|
| `payment()`, `billable()` | relations | |
| `invoice()` | `BelongsTo` | The invoice a receipt settles |
| `receipts()` | `HasMany` | Receipts of an invoice |
| `isInvoice()`, `isReceipt()`, `isPaid()`, `isVoid()` | `bool` | |
| `money()` | `Money` | `total` + `currency` |
| `sellerDetails()`, `buyerDetails()` | `BillingDetails` | From the snapshot |
| scope `forBillable(Model $billable)` | | |

## BillingWebhookCall

`Fomvasss\Billing\Webhooks\BillingWebhookCall`, table `billing_webhook_calls`, `MassPrunable` (older than `webhook.prune_after_days`). `storeWebhook(string $gateway, Request $request)` stores a call with credential headers redacted; `saveException(Throwable $e)`.

## The Billable trait

`Fomvasss\Billing\Concerns\Billable` — `tenantId()` (null), `payments()`, `subscriptions()`, `paymentMethods()`, `defaultPaymentMethod()` (`MorphOne`, `is_default`), `defaultPaymentMethodFor(string $gateway)`, `activeSubscription(?string $planCode = null)` (latest active), `hasActiveSubscription(?string $planCode = null)`.
