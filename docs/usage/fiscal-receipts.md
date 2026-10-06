# Fiscal receipt items

The package has one neutral basket shape — a list of lines in minor units — and each driver maps it to its gateway's own fiscal or line-item field.

```php
[
    ['name' => 'Coffee beans 1 kg', 'qty' => 2, 'unitAmount' => 45000, 'sku' => 'BEANS-1KG'], // sku optional
    ['name' => 'Delivery', 'qty' => 1, 'unitAmount' => 9000],
]
```

## From the payable

A payable implementing `Contracts\HasReceiptItems` provides the basket automatically — `charge()`, `startSubscription()` and `chargeWithMethod()` all fill `ChargeOptions::$receiptItems` from it when the caller didn't pass any:

```php
use Fomvasss\Billing\Contracts\HasReceiptItems;
use Fomvasss\Billing\Contracts\Payable;

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

Explicit `ChargeOptions(receiptItems: [...])` wins over the payable.

## The total must match

The sum of `round(unitAmount × qty)` over the lines must equal `$payment->amount`, or the charge throws `BillingException` before the gateway is called. Not pedantry: Stripe bills the sum of its line items rather than your amount, and the paid callback — checked against `amount` — would then refuse to mark the payment paid.

## Per gateway

| Gateway | Where the basket goes |
|---|---|
| Monobank | `merchantPaymInfo.basketOrder` (`name`, `qty`, `sum` = unit amount, `code` = sku), on checkout and off-session charges |
| WayForPay | `productName[]`, `productCount[]`, `productPrice[]` (signed). Without items — one line named after the description |
| Hutko | `reservation_data` — base64 JSON for Hutko's programmable RRO, prices in decimal units; on checkout and `/api/recurring`. Hutko may append its own lines (a card-BIN discount) |
| Stripe | Checkout `line_items` (display only, not fiscal). Off-session PaymentIntents have no basket — ignored |
| Paddle | One transaction item per line. Quantity pinned so the customer can't change it |
| LiqPay | **Not used.** LiqPay's `rro_info` references goods registered in your LiqPay account by id — pass it via `raw` |

LiqPay fiscalization:

```php
Billing::charge($payment, new ChargeOptions(raw: [
    'rro_info' => [
        'items' => $order->items->map(fn ($item) => [
            'id' => $item->product->liqpay_goods_id,
            'amount' => $item->qty,
            'price' => $item->unit_price / 100,
            'cost' => $item->total / 100,
        ])->all(),
        'delivery_emails' => [$order->user->email],
    ],
]));
```

## Renewals

A scheduled renewal has no caller and its payable is the package's `Subscription`, which doesn't implement `HasReceiptItems` — so a first payment would be fiscalized and every renewal after it bare. Renewal options come from `Contracts\RenewalChargeOptionsContract` instead.

The cheap way, for a subscription that just needs one line:

```php
// config/billing.php
'renewal' => ['receipt_items' => true], // BILLING_RENEWAL_RECEIPT_ITEMS=true
```

Every renewal then carries one line: the whole payment amount, `qty` 1, named after the plan — or after `prices.meta['receipt_name']` — with `prices.meta['receipt_sku']` as `sku` when set. It never splits into `qty × unitAmount`: a per-seat or metered total may not divide evenly in minor units.

Anything richer (per-seat lines, tax codes, LiqPay's `rro_info`, the customer's email and IP) — bind your own resolver:

```php
use Fomvasss\Billing\Contracts\RenewalChargeOptionsContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Subscription;

// AppServiceProvider::register()
$this->app->bind(RenewalChargeOptionsContract::class, fn () => new class implements RenewalChargeOptionsContract
{
    public function resolve(Subscription $subscription, Payment $payment): ChargeOptions
    {
        $organization = $subscription->billable;

        return new ChargeOptions(
            receiptItems: [[
                'name' => "Subscription «{$subscription->price->plan->name}», 1 month",
                'qty' => 1,
                'unitAmount' => $payment->amount,
                'sku' => $subscription->price->plan->code,
            ]],
            customerEmail: $organization->billing_email,
            customerIp: $organization->last_ip, // LiqPay requires one for paytoken
            description: "Subscription renewal #{$subscription->id}",
        );
    }
});
```

> [!WARNING]
> A resolver that throws — or returns a basket that doesn't add up to `$payment->amount` — fails that renewal: the payment is written off as `failed` and the subscription enters dunning. Keep it to reading what is already on the models, with no outbound calls.
