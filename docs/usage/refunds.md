# Refunds

`Billing::refund()` makes the gateway call **and** records it: a child `Payment` row (`type` `refund`, `parent_payment_id` = the charge) and a `PaymentRefunded` event carrying that row. The original charge is never modified.

```php
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Support\Money;

$refund = Billing::refund($payment);                         // the unrefunded remainder
$refund = Billing::refund($payment, new Money(2500, 'UAH')); // partial

$payment->refundedAmount();       // int, minor units — paid refund rows, soft-deleted ones included
$payment->refundableRemainder();  // amount minus paid AND pending refunds
$payment->refunds;                // child rows
$refund->parentPayment;           // the charge
```

Guards, all throwing `BillingException`:

- only a `paid` charge can be refunded (not a refund row);
- the currency must match the charge's;
- the amount must be positive and not exceed `refundableRemainder()`;
- the gateway refused (all gateways answer a refusal with HTTP 200 and a status field — Monobank `status: failure`, LiqPay `result != ok`, Hutko `response_status: failure` or `reverse_status: declined`, Stripe `failed`/`canceled`, Paddle `rejected`) — no row is written.

A gateway without refund support throws `NotSupportedException`. Check `Billing::gateways()[$name]['capabilities']['refunds']`.

| Gateway | API refunds |
|---|---|
| Monobank | ✓ (`invoice/cancel`, partial supported) |
| LiqPay | ✓ (`action: refund`) |
| Hutko | ✓ (`reverse/order_id`, partial supported) |
| Stripe | ✓ (`/refunds` with an idempotency key) |
| Paddle | ✓ — waits for approval, see below |
| WayForPay | ✗ — refund from the WayForPay dashboard; the reversal webhook is recorded |

Soft-deleting a refund row doesn't re-open room for another refund: `refundedAmount()` and `refundableRemainder()` count trashed rows.

## Concurrency

Calls for the same payment are serialized with a cache lock (`billing:refund:{id}`, 60 s); a second concurrent call throws `BillingException` instead of sending money twice. The lock is only as wide as your cache store:

| Store | Protects |
|---|---|
| `redis`, `memcached`, `database` | Every process on every server |
| `file` | Every process on one machine (`flock()`), not across servers |
| `array` | Nothing — per process |

## Paddle: refunds that wait for approval

Paddle reviews most refunds before money moves (the sandbox approves every ten minutes). `Billing::refund()` then returns a row with status `pending` and fires nothing:

- `refundedAmount()` doesn't count it; `refundableRemainder()` reserves it, so the same money can't be refunded twice;
- Paddle's approval (`adjustment.updated`) turns it `paid` and fires `PaymentRefunded`;
- a rejection turns it `failed` with a log warning and **no event** (a `PaymentFailed` on a renewal's refund would start dunning);
- `billing:reconcile-pending-payments` polls a pending refund too, in case the approval webhook got lost.

If you list `$payment->refunds`, filter by status: a `pending` row is a request, not money returned.

A full refund (nothing refunded before) goes as Paddle's `type: full`. A partial one is scaled from the payment's amount to what the customer actually paid (tax included) and spread over the transaction lines that still hold refundable money.

## Refunds issued outside the package

Money also goes back without `Billing::refund()` — from the gateway's dashboard, or by a cardholder dispute. Where the webhook is unambiguous the package records it exactly like its own refund: a child row, `PaymentRefunded`, `refundedAmount()` kept honest.

| Gateway | Reversal webhook | Recorded from |
|---|---|---|
| Stripe | `charge.refunded` | `amount_refunded` — the charge's running total |
| Monobank | invoice status `reversed` | `cancelList` — the sum of successful reversals |
| Hutko | the purchase callback again, with `reversal_amount` | `reversal_amount` — the order's running total |
| LiqPay | status `reversed` | `refund_amount`, read as the order's running total |
| WayForPay | `Refunded` / `Voided` | `amount` — this reversal's own sum, deduplicated by `processingDate` |
| Paddle | `adjustment.created`/`.updated`, action `refund`, once `approved` | the adjustment total, scaled to the payment's terms |

Running totals make re-deliveries and out-of-order callbacks settle to the same number instead of stacking, and the echo of a refund you issued yourself adds nothing. `PaymentRefunded` fires exactly once per refund row, whoever recorded it.

Edge cases worth knowing:

- **WayForPay** reports each reversal's own amount, so a re-delivery is recognized by the reversal's identity (`orderReference` + `processingDate`). Two reversals of the same payment inside one second collapse into one row.
- **LiqPay** documents `refund_amount` only as "Сума повернення". It is read as a running total; if it is per-reversal, a second partial refund would be under-recorded, never double-counted.
- **Paddle chargebacks** are logged, not recorded.
- A reversal a driver recognizes but can't put an amount on is logged: watch for `a reversal was reported for a payment but not recorded` and record those by hand.

## Listening for refunds

`PaymentRefunded::$payment` is the **refund row**. Reach the original charge through `$event->payment->parentPayment`, and the thing paid for through `$event->payment->payable` (copied from the charge):

```php
Event::listen(function (PaymentRefunded $event) {
    $charge = $event->payment->parentPayment;
    $order = $event->payment->payable;
});
```
