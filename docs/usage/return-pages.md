# Return pages and the pay link

## Return pages

Configure your final pages once — app routes, or a frontend/SPA on another origin:

```php
// config/billing.php
'return_urls' => [
    'success' => 'https://app.example.com/checkout/success',
    'failed' => 'https://app.example.com/checkout/failed',
],
```

The gateway is not sent there directly. It gets the package's return route, `billing/return/{payment}/{outcome}` (`billing.return`, GET and POST, no CSRF), which:

1. fires `CheckoutReturned($payment, $outcome, $data)`;
2. 303-redirects to `return_urls.{outcome}` with `?payment={id}` appended (and any incoming query params).

The hop exists because WayForPay and Hutko return the customer with an auto-submitted **POST**, which the route accepts without CSRF exceptions on your side, and the 303 turns into a plain GET — something a SPA page could never receive directly.

> [!WARNING]
> `success`/`failed` name the return **slot**, not the verdict. Only Stripe (`success_url`/`cancel_url`) and Paddle (the overlay closed without paying) ever use the `failed` slot. Monobank, LiqPay, WayForPay and Hutko have a single return URL, so their customers land on your `success` page whatever happened — including a declined card. The page must read the real state: `$payment->isPaid()`, `isFailed()`, or "processing" while the webhook hasn't arrived.

The return page is UX only. The browser may come back before the webhook, after it, or never; `CheckoutReturned` and its `$data` (whatever the gateway put in the redirect) are unverified. Never fulfil an order from them.

```php
Route::get('/checkout/success', function (Request $request) {
    $payment = Payment::findOrFail($request->query('payment'));

    return view('checkout.result', [
        'paid' => $payment->isPaid(),
        'failed' => $payment->isFailed() || $payment->status === PaymentStatus::Canceled,
    ]);
});
```

If `return_urls.{outcome}` is not configured, the return route answers 404.

### Return params

Need more than the payment id on your page? `ChargeOptions::$returnParams` rides through the hop as query parameters:

```php
Billing::charge($payment, new ChargeOptions(returnParams: ['order' => $order->number]));
// → https://app.example.com/checkout/success?order=1042&payment={id}
```

`payment` always wins over a same-named param. These are display hints — not proof of anything.

### Bypassing the return route

`ChargeOptions(successUrl: ..., failUrl: ...)` sends a URL to the gateway as is — no `CheckoutReturned`, no `?payment=`. With WayForPay or Hutko the POST-style return is then yours to accept. Monobank has one `redirectUrl` for both outcomes, so `failUrl` is ignored there.

## Permanent pay link

`route('billing.pay', $payment)` (`GET billing/pay/{payment}`) is the URL to put in an email or on an invoice — unlike `payment_url`, it never goes stale:

| Payment state | The link |
|---|---|
| `pending` with a live checkout (`hasActivePaymentUrl()`) | Redirects to the gateway |
| Expired, `failed` or `canceled` | Issues a fresh checkout via `charge()`, then redirects. The old gateway-side checkout is left to expire (Paddle cancels it) |
| `paid` | Redirects to `return_urls.success` with `?payment={id}` |
| A refund row | 404 |

Every visit fires `PaymentLinkOpened($payment)` — an analytics signal ("opened twice, never paid"), nothing more.

The link is public and unauthenticated by design: it opens a checkout, not a document, and the id is a UUID. Re-issues are serialized per payment with a cache lock (`billing:reissue:{id}`, waits up to 15 s), so a double click or a mail client prefetching links doesn't create two live checkouts — the second request reuses the link the first one stored. The lock is only as wide as your cache store (see [Refunds](refunds.md#concurrency)).

A gateway may refuse a re-issue for a reference it considers final; that surfaces as the driver's exception.

### What a re-issue sends

A re-issue builds its `ChargeOptions` through `Contracts\ReissueChargeOptionsContract` — **empty by default**. The original call's options were never stored, so `saveCard`, `description`, `raw` and the like are not repeated. Receipt items still auto-fill from a `HasReceiptItems` payable, but the package's own `Subscription` isn't one.

Two omissions matter: `saveCard` (without a token a package-managed subscription can never renew) and the fiscal basket. Bind your own resolver to carry the intent across:

```php
use Fomvasss\Billing\Contracts\ReissueChargeOptionsContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Subscription;

class ReissueOptions implements ReissueChargeOptionsContract
{
    public function resolve(Payment $payment): ChargeOptions
    {
        return new ChargeOptions(
            saveCard: $payment->payable instanceof Subscription,
            description: "Payment {$payment->number}",
        );
    }
}

// AppServiceProvider::register()
$this->app->bind(ReissueChargeOptionsContract::class, ReissueOptions::class);
```

## LiqPay's form page

LiqPay accepts only a browser-submitted POST with signed fields. `charge()` caches that form (until `payment_url_expires_at`) and points `payment_url` at `billing/checkout/{payment}` (`billing.checkout-form`), a page that auto-submits it. Once the cache entry expires the page answers 404 — open the pay link to get a new one.

## Paddle's checkout page

Paddle has no hosted web checkout: its payment link opens a page on your domain that loads Paddle.js. The package ships it as `billing/paddle/checkout` (`billing.paddle.checkout`), see [Paddle](gateways/paddle.md#checkout-page).
