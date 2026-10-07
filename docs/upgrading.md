# Upgrading

The package is pre-1.0: the API and schema may change between minor versions. The full list is in the [CHANGELOG](https://github.com/fomvasss/laravel-billing/blob/master/CHANGELOG.md).

> [!WARNING]
> New columns are added to the package's **existing** migration files, and `vendor:publish` skips a migration that is already published. An installed app doesn't get them by re-publishing — compare the published files with `vendor/fomvasss/laravel-billing/database/migrations` and write your own migration for the difference. The columns each release added are listed below.

## 0.12

- `billing.invoices.number_per_tenant` (default `true`, unchanged behaviour). `false` numbers all tenants' documents in one sequence.
- 0.12.1: an invoice issued for an already paid payment no longer names the subscription's (next) period.
- 0.12.2: `auto_invoice`/`auto_receipt` issue nothing while the seller has no name.
- 0.12.3: with a morph map, drivers now store the billable's alias instead of its class name in `billing_payment_methods.billable_type`. Without a morph map nothing changes. With one, rows saved by `attachPaymentMethod()` before the upgrade still hold the class name and stay invisible to `$billable->paymentMethods` and renewals — rewrite them once:

  ```php
  use Fomvasss\Billing\Models\PaymentMethod;
  use Illuminate\Database\Eloquent\Relations\Relation;

  foreach (Relation::morphMap() as $alias => $class) {
      PaymentMethod::where('billable_type', $class)->update(['billable_type' => $alias]);
  }
  ```

  If a billable then has two rows for the same card (one from a checkout, one attached by hand), delete the duplicate. On Stripe, a billable may also have extra customers created before the fix; they are harmless, the package reuses the customer of the billable's first saved card.

## 0.11

- `issueInvoice()` accepts a paid payment (the invoice comes out paid) instead of throwing.
- New `billing.invoices.auto_invoice` and `InvoiceItemsContract`.

## 0.10 — invoices

- New optional group `billing-migrations-invoices` (`billing_invoices`, `billing_document_sequences`) and `billing.invoices.*` config — publish both and set `BILLING_INVOICES_ENABLED=true` to use them.

## 0.9 — Paddle, provider-managed subscriptions

- **Stripe API version pinned** to `2026-08-26.dahlia`. Re-create an existing endpoint: `php artisan billing:stripe-register-webhook --fresh`, then store the new signing secret. The command warns when an endpoint's version differs.
- Re-run `billing:stripe-register-webhook` (without `--fresh` it now updates events in place) to subscribe to `charge.refunded`, `invoice.paid` and `customer.subscription.*`.
- New columns: `billing_subscriptions.provider_synced_at` (nullable datetime) and an index on `(gateway, external_id)`.
- `SubscriptionGatewayContract` is deprecated in favour of `StartsProviderSubscriptions` + `ManagesProviderSubscriptions`.
- `cancel()`/`pause()`/`resume()`/`swapPlan()` on a row with `external_id` now go to the driver and throw `NotSupportedException` when it can't — they no longer change only the local row. The payment-outcome listener now skips such rows.
- Custom signature validators should resolve credentials by `$request->route('gateway')` (and `WebhookTenant::fromRequest()`), not a hard-coded name.
- Refund rows may be `pending` (Paddle) — filter `$payment->refunds` by status. `refundableRemainder()` reserves pending ones; `Payment::recordRefundOf()` takes a status.
- Paddle setup: `billing:paddle-register-webhook`, the default payment link, domain approval.

## 0.8

- New status `SubscriptionStatus::Incomplete` (no migration — plain string column). If your checkout created `trialing` rows with a past `trial_ends_at`, create them as `incomplete` instead.
- 0.8.1: `charge()` resets a non-paid payment to `pending` when it issues a checkout.

## 0.7

- `TrialEnded` event; `billing:expire-trials` expires trials row by row.
- Every path into `canceled` goes through `markCanceled()`, which clears `next_retry_at` and `grace_ends_at`.

## 0.6

- New column `billing_payments.initiation` (nullable string(20)).
- 0.6.1: `billing_payment_methods` unique index is now `(gateway, billable_type, billable_id, external_customer_id, external_id)`, and `external_customer_id`/`external_id` are `varchar(191)`.
- 0.6.2: `tenant_id` is derived from the billable on create. Backfill old rows yourself if reports need them.

## 0.5

- New columns: `billing_prices.quota_interval`, `quota_interval_count`; `billing_subscriptions.quota_period_ends_at`.
- New command `billing:reset-usage-quotas` (scheduled hourly).
- 0.5.2: `ReissueChargeOptionsContract` (default unchanged).

## 0.4

- `isActive()`/`active()` derive access from the row's dates (trial end, `cancels_at`, due `pause_ends_at`) instead of waiting for the scheduler.
- `billing:expire-trials` and the new `billing:send-period-notices` run hourly.
- New columns: `billing_prices.period_ending_notices`, `billing_subscriptions.period_notices_sent`.
- `SubscriptionRenewed` has `previousStatus`.

## 0.3

- Hutko refunds via the API.

## 0.2 — retry ladder

- `billing.retry_interval_hours` / `BILLING_RETRY_INTERVAL_HOURS` replaced by `billing.retry_intervals` (a list); new column `billing_prices.retry_intervals`.
- `max_recurring_attempts` defaults to `4` and counts attempts, not retries.
- `grace_ends_at` = `next_retry_at` + `grace_period_days`.
- An unparsable or non-positive interval throws.
- `RenewalChargeOptionsContract`, `billing.renewal.receipt_items` for fiscal baskets on renewals.
