# Payments

A `Payment` (`Fomvasss\Billing\Models\Payment`, table `billing_payments`) is one movement of money: a charge, or a refund of one. Your app creates the row; a gateway driver turns it into a checkout; the gateway's webhook decides whether it was paid.

## Payable and billable

Every payment has two polymorphic owners:

- **`payable`** — what is being paid for: your `Order`, a top-up, the package's own `Subscription` for renewals
- **`billable`** — who pays: a `User`, an `Organization`

Any Eloquent model can be a payable. Implementing the marker interface `Contracts\Payable` is optional — it only helps type-hinting. A payable that implements `Contracts\HasReceiptItems` provides the [fiscal basket](fiscal-receipts.md).

A billable should implement `Contracts\Billable` — the package calls its `tenantId()` to pick per-tenant credentials. The `Concerns\Billable` trait implements it (`tenantId()` returns `null`) and adds relations:

```php
use Fomvasss\Billing\Concerns\Billable as BillableConcern;
use Fomvasss\Billing\Contracts\Billable;

class Organization extends Model implements Billable
{
    use BillableConcern;

    // override only for multi-tenancy, see "Tenants and multiple accounts"
    public function tenantId(): ?string
    {
        return (string) $this->id;
    }
}
```

```php
$organization->payments;                       // morphMany — chain scopes: ->payments()->paid()
$organization->subscriptions;
$organization->paymentMethods;
$organization->defaultPaymentMethod;           // one default card (per gateway — see below)
$organization->defaultPaymentMethodFor('monobank');
$organization->activeSubscription('pro');      // ?Subscription, by plan code; null code = any plan
$organization->hasActiveSubscription('pro');   // bool — the gate/middleware one-liner
```

`is_default` is tracked per gateway, so a billable with cards on two gateways has two defaults — `defaultPaymentMethod` returns one of them, `defaultPaymentMethodFor()` is the precise pick.

`tenant_id` on payments, subscriptions, payment methods and invoices is filled from the billable's `tenantId()` on create when you leave it empty; a value you pass is never overwritten.

## Charging

```php
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;

$payment = Payment::create([
    'gateway' => 'liqpay',
    'amount' => 10000,        // always minor units — 100.00
    'currency' => 'UAH',
    'payable_type' => $order->getMorphClass(),
    'payable_id' => $order->id,
    'billable_type' => $user->getMorphClass(),
    'billable_id' => $user->id,
]);

$result = Billing::charge($payment, new ChargeOptions(
    description: "Order #{$order->number}",
    customerEmail: $user->email,
));

return redirect($payment->payment_url);
```

`status` defaults to `pending` and `type` to `charge` at the database level.

`charge()` resolves the driver for `$payment->gateway` with the billable's tenant, auto-fills the fiscal basket, checks it against the amount, calls the driver and writes back onto the row:

| Column | Value |
|---|---|
| `external_id` | The gateway's reference for this checkout (Monobank invoice id, Stripe session id, Paddle transaction id). LiqPay, WayForPay and Hutko return none — they find the payment by its own id, and the webhook fills in their transaction id where they have one |
| `payment_url` | Always a plain redirectable link |
| `payment_url_expires_at` | The checkout's lifetime (`link_ttl_minutes`, or the gateway's own for Stripe) |
| `initiation` | `manual`, unless `ChargeOptions::$initiation` says otherwise |
| `raw_response` | The gateway's response, for support and debugging |
| `status` | Reset to `pending` — unless the payment is already `paid` |

It returns a `PaymentResult` (`url` or `form`, `expiresAt`, `externalId`, `raw`). Use it only if you build your own response (an SPA API): every gateway sets `url` except LiqPay, which sets `form` (`['action' => ..., 'fields' => [...]]`) — `payment_url` already wraps that form in a self-submitting page.

`charge()` is safe to call again on the same payment once the link expired — the [permanent pay link](return-pages.md#permanent-pay-link) does exactly that.

> [!NOTE]
> `charge()` doesn't check `hasActivePaymentUrl()` itself: calling it twice issues two checkouts. Paddle cancels the previous transaction on re-issue; on the other gateways the old link simply lives until it expires.

### ChargeOptions

`Fomvasss\Billing\DTO\ChargeOptions`, all arguments optional:

| Argument | Type | Meaning |
|---|---|---|
| `receiptItems` | `array` | Fiscal basket, `[['name', 'qty', 'unitAmount', 'sku'?], ...]`. Auto-filled from a `HasReceiptItems` payable when empty. Must add up to the amount — see [Fiscal receipt items](fiscal-receipts.md) |
| `customerEmail` | `?string` | Monobank `customerEmails`, WayForPay `clientEmail`, Hutko `sender_email`, Stripe `customer_email` |
| `customerIp` | `?string` | Off-session charges: LiqPay `ip`, Hutko `client_ip`. Both fall back to `127.0.0.1` |
| `locale` | `?string` | LiqPay `language` (narrowed to `uk`/`en`), Hutko `lang`, Stripe `locale`, Paddle checkout locale |
| `description` | `?string` | Checkout description. Fallback is `Payment #{id}` where the gateway needs one |
| `saveCard` | `bool` | Tokenize the card during this charge, see [Saved cards](saved-cards.md) |
| `successUrl` | `?string` | Send the customer here instead of the package return route |
| `failUrl` | `?string` | Same, for the failure slot (Stripe, Paddle) |
| `webhookUrlParams` | `array` | Extra query params on the callback URL — a routing hint, never trusted. The package adds `tenant` itself |
| `returnParams` | `array` | Query params forwarded to your return page, see [Return pages](return-pages.md#return-params) |
| `initiation` | `?PaymentInitiation` | Who started the charge — overrides the default |
| `raw` | `array` | Gateway-specific request fields, merged under the driver's own: they can add a field, never override the amount or the reference |

`raw` is read only by the driver you charge through. Examples: LiqPay `rro_info`, Monobank `agentFeePercent`, Stripe `automatic_tax`, Paddle `discount_id`. On Hutko off-session charges `raw` keys are signed — an unknown key breaks the signature.

## Manual and offline payments

A cash or bank-transfer payment needs no driver — create the row in its final state:

```php
Payment::create([
    'status' => PaymentStatus::Paid,
    'gateway' => null, // or a free-text label such as 'cash' — never charged through
    'amount' => 10000,
    'currency' => 'UAH',
    'payable_type' => $order->getMorphClass(),
    'payable_id' => $order->id,
    'billable_type' => $user->getMorphClass(),
    'billable_id' => $user->id,
]);
```

`paid_at` is stamped automatically whenever `status` becomes `paid` (and cleared when it moves away). A row created this way fires no `PaymentSucceeded` — dispatch your own logic, and call `Billing::issueReceipt()` yourself if you use [invoices](invoices.md).

> [!WARNING]
> `charge()`, `chargeWithMethod()` and `refund()` need a registered gateway name. A payment with `gateway` null hits a `TypeError`; a free-text label that isn't registered throws `BillingException` ("not registered").

## Payment numbers

A UUID is a poor thing to read over the phone. `payments.number` (unique) is a human-facing reference for emails and support, and `Payment::findByNumber()` looks it up. The package never generates it — numbering schemes are project-specific — assign yours in a hook:

```php
// AppServiceProvider::boot()
Payment::creating(function (Payment $payment) {
    $payment->number ??= 'PAY-' . now()->format('Y') . '-' . str_pad((string) PaymentSequence::next(), 6, '0', STR_PAD_LEFT);
});
```

Invoices and receipts have their own numbers, see [Invoices](invoices.md#numbers).

## Who started the charge

`payments.initiation` (`PaymentInitiation`) records the initiator, not the mechanism:

| | `manual` | `automatic` |
|---|---|---|
| Written by | `charge()`, `startSubscription()` | `chargeWithMethod()`, provider-created renewal rows |
| Means | a person was there | nobody was — a scheduled renewal, a dunning retry |

```php
$payment->isManual();
$payment->isAutomatic();
```

Override it when the two disagree:

```php
// one-click "pay with the saved card": off-session code path, person present
Billing::chargeWithMethod($payment, $method, new ChargeOptions(initiation: PaymentInitiation::Manual));
```

The column is nullable and never guessed: a row created outside those methods has no initiation, and both helpers answer `false`.

## Gateway fee and net amount

`amount` is always what the customer paid — refund caps, amount verification and reconciliation rely on it. The merchant side lives next to it:

- `payments.fee` — the gateway's commission in minor units, filled from the paid callback (or status poll) where the gateway reports it: Monobank `paymentInfo.fee`, LiqPay `receiver_commission`, WayForPay `fee`, Hutko `fee`, Paddle `details.totals.fee`. Stripe's webhook carries no fee — it stays `null`
- `$payment->netAmount()` — `amount - fee`, `null` while the fee is unknown

`null` means unknown, `0` means known to be zero. The column is yours to write too — `PaymentSucceeded` fires after the driver filled what the gateway reported:

```php
Event::listen(function (PaymentSucceeded $event) {
    $payment = $event->payment;

    if ($payment->fee === null) {
        $payment->update(['fee' => (int) round($payment->amount * 2.9 / 100)]);
    }
});
```

## What a payment is for

A payment alone says who paid and how much, not what for. Either point `payable` at a model of your own (`StorageAddonPurchase`, `Order`) and branch on `instanceof` in the listener, or keep it simple with `payments.meta` — a JSON column the package never reads:

```php
$payment = Payment::create([
    // ...
    'payable_type' => $organization->getMorphClass(),
    'payable_id' => $organization->id,
    'meta' => ['product' => 'storage_addon', 'gb' => 5],
]);

Event::listen(function (PaymentSucceeded $event) {
    if (($event->payment->meta['product'] ?? null) === 'storage_addon') {
        $event->payment->payable->increment('extra_storage_gb', $event->payment->meta['gb']);
    }
});
```

## Outcomes

The webhook (or [reconciliation](scheduling.md#billingreconcile-pending-payments)) is the only thing that changes a payment's status, and it fires:

| Status | Event | Meaning |
|---|---|---|
| `paid` | `PaymentSucceeded` | Money received, amount and currency verified |
| `failed` | `PaymentFailed` | The card was tried and refused |
| `canceled` | `PaymentCanceled` | The checkout expired or was voided, or reconciliation wrote it off — nobody's card was refused |

A `paid` payment is never moved to another status by a later callback (`Payment::transitionTo()`); a `failed`/`canceled` one can still become `paid` — the customer paid a re-issued checkout. See [Webhooks → Guarantees](webhooks.md#what-the-pipeline-guarantees).

Helpers: `isPaid()`, `isPending()`, `isFailed()`, `isRefund()`, `hasActivePaymentUrl()`, scopes `paid()`, `pending()`, `forBillable($model)`. Full list — [Models](../reference/models.md#payment).
