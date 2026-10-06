# DTOs and enums

## DTOs

Namespace `Fomvasss\Billing\DTO`, all `final readonly`.

### ChargeOptions

`receiptItems` (array), `customerEmail`, `customerIp`, `locale`, `description`, `saveCard` (bool), `successUrl`, `failUrl`, `webhookUrlParams` (array), `returnParams` (array), `initiation` (`?PaymentInitiation`), `raw` (array). `withReceiptItems(array)` returns a copy. Meaning of each — [Payments → ChargeOptions](../usage/payments.md#chargeoptions).

### PaymentResult

| Property | Type | Meaning |
|---|---|---|
| `url` | `?string` | Redirect checkout |
| `form` | `?array` | `['action' => ..., 'fields' => [...]]` for a form-only gateway (LiqPay) |
| `expiresAt` | `?DateTimeInterface` | Lifetime of this link |
| `externalId` | `?string` | Gateway reference |
| `raw` | `array` | Gateway response |
| `pending` | `bool` | `refund()` only: accepted, awaiting approval (Paddle) |

### WebhookResult

`type` (`WebhookEventType`), `status` (string), `payment`, `subscription`, `paymentMethod`, `externalId`, `raw`, `snapshot`. `dedupKey()` = `{type}:{status}:{externalId}` or null.

| `type` | `status` values |
|---|---|
| `Payment` | `succeeded`, `failed`, `refunded`, `canceled` |
| `Subscription` | `synced` (applies `snapshot`); event-only `created`, `renewed`, `payment_failed`, `canceled`, `trial_will_end` |
| `PaymentMethod` | `attached`, `detached` |
| `Ignored` | — |

### SubscriptionSnapshot

`externalId`, `status` (`SubscriptionStatus`), `currentPeriodEndsAt` (end of the **paid** period), `cancelsAt`, `trialEndsAt`, `pauseEndsAt`, `priceId`, `occurredAt`. The whole state, not a delta: null `cancelsAt`/`pauseEndsAt` clear the columns; null `currentPeriodEndsAt`/`trialEndsAt`/`priceId` keep what's there.

### BillingDetails

`name`, `taxId`, `address`, `email`, `phone`, `iban`, `bank`, `logo`, `vatId`, `brand`. `fromArray()` reads snake_case keys (`tax_id`, `vat_id`); `toArray()` drops empty values.

### InvoiceDocument

What a template gets as `$document` — see [Invoices → Customizing the template](../usage/invoices.md#customizing-the-template).

### GatewayHealth

`ok` (bool), `message` (`?string`), `latencyMs` (`?float`); `GatewayHealth::up()`, `::down()`.

### ResolvedAmount

`money` (`Money`, per unit), `convertedFromCurrency`, `exchangeRate`, `exchangeRateAt` — set only when a converter was used.

## Enums

Namespace `Fomvasss\Billing\Enums`. String-backed ones are cast on the models, accept plain strings on write, and have `label()`.

| Enum | Column | Cases |
|---|---|---|
| `PaymentStatus` | `payments.status` | `pending`, `paid`, `failed`, `canceled` |
| `PaymentType` | `payments.type` | `charge`, `refund` |
| `PaymentInitiation` | `payments.initiation` | `manual`, `automatic` |
| `SubscriptionStatus` | `subscriptions.status` | `incomplete`, `trialing`, `active`, `paused`, `past_due`, `canceled`, `ended` |
| `PricingType` | `prices.pricing_type` | `flat`, `licensed`, `metered` |
| `Interval` | `prices.interval`, `prices.quota_interval` | `minute`, `hour`, `day`, `week`, `month`, `year` |
| `InvoiceType` | `invoices.type` | `invoice`, `receipt` (`series()`: `INV`, `RCP`) |
| `InvoiceStatus` | `invoices.status` | `issued`, `paid`, `void` |
| `WebhookEventType` | — (pure enum) | `Payment`, `PaymentMethod`, `Subscription`, `Ignored` |

`InvoiceType` and `InvoiceStatus` have no `label()`.

`Fomvasss\Billing\Support\Rounding` (string-backed): `HalfUp`, `HalfDown`, `HalfEven`, `Up`, `Down`, used by `Money::multiply()`.
