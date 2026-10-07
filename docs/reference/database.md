# Database tables

All keys are UUIDs. Amounts are unsigned big integers in minor units. Morph ids are `string(64)`.

## billing_payments

Group `billing-migrations-core`.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `status` | string(20), default `pending` | `PaymentStatus` |
| `type` | string(20), default `charge` | `PaymentType` |
| `initiation` | string(20), nullable | `PaymentInitiation` |
| `number` | string(64), nullable, unique | Your human-facing reference |
| `gateway` | string(50), nullable | Gateway name; null or free text for manual payments |
| `amount` | unsigned bigint | What the customer pays |
| `fee` | unsigned bigint, nullable | Gateway commission; null = unknown |
| `currency` | string(3) | |
| `converted_from_currency`, `exchange_rate` (decimal 18,8), `exchange_rate_at` | nullable | Conversion facts |
| `external_id` | string, nullable | Gateway reference |
| `payment_url` | string(2048), nullable | |
| `payment_url_expires_at`, `paid_at` | timestamp, nullable | |
| `raw_response` | json, nullable | Gateway response of charge/refund |
| `meta` | json, nullable | Yours |
| `tenant_id` | string(100), nullable | |
| `payable_type`, `payable_id` | string, string(64) | |
| `billable_type`, `billable_id` | string, string(64) | |
| `parent_payment_id` | uuid, nullable | Refund → charge (indexed, no FK) |
| timestamps, `deleted_at` | | Soft deletes |

Indexes: `(tenant_id, status)`, `(status, created_at)`, `(gateway, external_id)`, payable, billable, `parent_payment_id`.

## billing_webhook_calls

Group `billing-migrations-core`. `id`, `name` (gateway, 50), `url` (2048, or `reconcile`/`refund` for synthetic claims), `external_id` (the dedup key), `headers`, `payload`, `exception` (json), timestamps. Unique `(name, external_id)`.

## billing_plans

Group `billing-migrations-subscriptions`. `id`, `code` (unique), `name`, `meta` (json), timestamps.

## billing_prices

Group `billing-migrations-subscriptions`.

| Column | Type | Notes |
|---|---|---|
| `pricing_type` | string(20), default `flat` | |
| `gateway` | string(50), nullable | |
| `currency`, `amount` | | |
| `interval` | string(20), nullable | `Interval`; null = one-off |
| `interval_count` | unsigned int, default 1 | |
| `trial_days` | unsigned int, default 0 | |
| `trial_ending_notices`, `period_ending_notices`, `retry_intervals` | json, nullable | Overrides; null = config |
| `grace_access` | boolean, nullable | Override; null = config |
| `external_price_id`, `unit_label` | string, nullable | Yours |
| `included_units` | decimal(18,4), nullable | Quota |
| `quota_interval` | string(20), nullable | |
| `quota_interval_count` | unsigned int, default 1 | |
| `is_active` | boolean, default true | |
| `meta` | json, nullable | |
| `plan_id` | uuid FK → plans, cascade delete | |

Index `(plan_id, gateway, currency)`.

## billing_subscriptions

Group `billing-migrations-subscriptions`.

| Column | Type | Notes |
|---|---|---|
| `status` | string(20), default `trialing` | `SubscriptionStatus` |
| `gateway` | string(50), nullable | Stamped by the first payment |
| `qty` | unsigned int, default 1 | Seats |
| `current_usage` | decimal(18,4), default 0 | |
| `external_id` | string, nullable | Non-null = provider-managed |
| `provider_synced_at` | datetime, nullable | Last applied provider snapshot |
| `trial_ends_at` | timestamp, nullable | |
| `trial_notices_sent`, `period_notices_sent` | json, nullable | Notice markers |
| `current_period_ends_at`, `quota_period_ends_at` | timestamp, nullable | |
| `cancels_at`, `pause_ends_at` | timestamp, nullable | |
| `grace_ends_at`, `next_retry_at` | timestamp, nullable | Dunning |
| `recurring_attempts` | unsigned int, default 0 | |
| `tenant_id` | string(100), nullable | |
| `billable_type`, `billable_id` | string, string(64) | |
| `price_id` | uuid FK → prices, restrict delete (since 0.12.8) | |

Indexes: billable, `(status, current_period_ends_at)`, `(status, pause_ends_at)`, `quota_period_ends_at`, `(gateway, external_id)`.

> [!WARNING]
> A price or plan with subscriptions (any status) can't be deleted: `Price::delete()` and `Plan::delete()` throw `BillingException`. Retire prices with `is_active = false` instead. `prices.plan_id` still cascades, so a plan whose prices have no subscriptions deletes with them. In a database created before 0.12.8 `subscriptions.price_id` cascades — a raw `DELETE` (query builder, not a model) still takes the subscriptions with it, see [Upgrading](../upgrading.md).

## billing_payment_methods

Group `billing-migrations-payment-methods`. `gateway`, `type` (default `card`), `brand`, `last4`, `expires_at` (datetime — card expiries go past 2038), `is_default`, `tenant_id`, billable morph, `external_customer_id` (191), `external_id` (191). Unique `(gateway, billable_type, billable_id, external_customer_id, external_id)` — one physical card may be saved by two billables.

## billing_invoices

Group `billing-migrations-invoices`. `type`, `status` (default `issued`), `number`, `series`, `currency`, `total`, `seller`, `buyer`, `items` (json), `extra` (json), `locale`, `template`, `issued_at`, `due_at`, `paid_at`, `payment_id` (FK → payments, null on delete), `invoice_id`, `tenant_id`, billable morph (nullable). Unique `(tenant_id, number)` and `(type, payment_id)`.

## billing_document_sequences

Group `billing-migrations-invoices`. `series`, `scope` (tenant or `''`), `year`, `last`. Unique `(series, scope, year)`.
