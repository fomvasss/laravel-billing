# Events

Namespace `Fomvasss\Billing\Events`. All use `Dispatchable` and `SerializesModels`; properties are `public readonly`.

## Payments

| Event | Properties | Fired by |
|---|---|---|
| `PaymentSucceeded` | `payment` | Webhook/poll: a payment became `paid`; Stripe/Paddle renewal rows |
| `PaymentFailed` | `payment` | Webhook/poll: refused; `process-recurring-charges` when an initiation fails |
| `PaymentCanceled` | `payment` | Webhook/poll: expired/voided; reconciliation write-offs |
| `PaymentRefunded` | `payment` — the **refund row** | `Billing::refund()` (non-pending), external refunds from webhooks, Paddle approvals |
| `PaymentMethodAttached` | `paymentMethod` | A new card row — webhook attach or `attachPaymentMethod()` |
| `PaymentMethodDetached` | `paymentMethod` | `detachPaymentMethod()` |
| `CheckoutReturned` | `payment`, `outcome` (`success`/`failed`), `data` (raw request) | The browser hit `billing.return` — unverified |
| `PaymentLinkOpened` | `payment` | Every visit of `billing.pay` |

## Subscriptions

| Event | Properties | Fired by |
|---|---|---|
| `SubscriptionCreated` | `subscription` | A provider-managed subscription linked for the first time |
| `SubscriptionRenewed` | `subscription`, `previousStatus` (`?SubscriptionStatus`) | A paid period granted — package renewal, first payment, provider snapshot |
| `SubscriptionPaymentFailed` | `subscription` | Each failed renewal attempt still in dunning; a provider row entering `past_due` |
| `SubscriptionAccessSuspended` | `subscription` | Once, entering `past_due` when `grace_access` is false |
| `SubscriptionCancelled` | `subscription` | Any path into `canceled` |
| `SubscriptionPaused` / `SubscriptionResumed` | `subscription` | `pause()`/`resume()`, `expire-pauses`, provider snapshots |
| `SubscriptionPeriodEnding` | `subscription`, `notice` (`string\|int`), `willRenew` (`bool`) | `billing:send-period-notices` |
| `TrialWillEnd` | `subscription`, `notice` (`?string`; null from Stripe's own event) | `billing:expire-trials`, Stripe `trial_will_end` |
| `TrialEnded` | `subscription` | `billing:expire-trials` |
| `UsageLimitReached` | `subscription` | `reportUsage()` crossing `included_units` |
| `SubscriptionQuotaReset` | `subscription` | `billing:reset-usage-quotas` |

## Documents

| Event | Properties | Fired by |
|---|---|---|
| `InvoiceIssued` | `invoice` (an invoice or a receipt) | `issueInvoice()`, `issueReceipt()`, auto documents |
| `InvoicePaid` | `invoice` | `PaymentSucceeded` settling an `issued` invoice |

Events fired from the webhook job run inside its transaction together with the dedup claim — see [Webhooks → Queue](../usage/webhooks.md#queue).
