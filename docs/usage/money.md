# Money and currencies

## Minor units everywhere

Every amount — `payments.amount`, `prices.amount`, `fee`, receipt items, `Money` — is an **integer in minor units**: `10000` is 100.00. Drivers whose gateway wants decimal units on the wire (LiqPay, WayForPay, Hutko's fiscal basket) convert inside the driver.

> [!WARNING]
> The package assumes **two-decimal currencies**. Zero-decimal (JPY) and three-decimal (BHD) currencies are not supported, and the built-in currency lists leave them out.

## The `Money` value object

`Fomvasss\Billing\Support\Money` — readonly, `amount` (int, never negative) and `currency`:

```php
use Fomvasss\Billing\Support\Money;

$money = new Money(129900, 'UAH');

Money::fromDecimal('19.99', 'UAH');   // 1999 — rounds; (int) (19.99 * 100) would be 1998
Money::parse('1 299,00', 'UAH');      // 129900 — for text a human typed

$money->toDecimal();                  // '1299.00' — always two decimals, dot, no grouping
$money->format();                     // '1 299,00 ₴' via Number::currency(); '1299.00 UAH' without ext-intl
$money->format('en');

$payment->money();                    // also feeMoney(), netMoney(), refundedMoney(), refundableRemainderMoney()
$price->money();
$invoice->money();
```

`fromDecimal()` trusts its input — it is the bridge from a `decimal` column or an API. `(float) '1 299,00'` is `1.0`, silently. For an admin field or a spreadsheet cell use `parse()`, which reads every separator convention and refuses to guess:

```php
Money::parse('1299', 'UAH');      // 129900
Money::parse('1 299,00', 'UAH');  // 129900, non-breaking spaces included
Money::parse('1.299,00', 'UAH');  // 129900
Money::parse('1,299.00', 'UAH');  // 129900
Money::parse('19.999', 'UAH');    // throws: more than two decimals
Money::parse('1,299', 'UAH');     // throws: thousands or a fraction?
```

`parse()` never touches a float: `'19.99'` is composed as `19 × 100 + 99`.

### Arithmetic

```php
$total = $a->plus($b);                          // currencies must match, else InvalidArgumentException
$left = $total->minus($paid);                   // a negative result throws
$line = $unit->multiply(3);                     // Rounding::HalfUp by default
$line = $unit->multiply(1.2, Rounding::HalfEven);

[$x, $y, $z] = (new Money(10000, 'UAH'))->allocate([1, 1, 1]); // 33.34 + 33.33 + 33.33
$shares = $discount->allocate([$line1->amount, $line2->amount]); // weighted

$a->equals($b); $a->isZero(); $a->isSameCurrency($b);
```

`allocate()` floors every share and hands the leftover units to the earliest ones — order the ratios accordingly. `Rounding` (`Support\Rounding`): `HalfUp`, `HalfDown`, `HalfEven`, `Up`, `Down`. `multiply()` takes a float factor, so an inexact factor (a tax rate, daily proration) is already approximate — reach for `brick/money` beyond that.

### What to store in your own tables

- Anything that feeds billing (tariffs, wallet balances, ledgers) — integer minor units. Accept `299.00` in a form, store `Money::parse($input, 'UAH')->amount`.
- Catalog prices humans edit and that reach billing only through an order total — `decimal(12,2)` is fine; convert once where the `Payment` is created.
- Never `float` columns, never float arithmetic over money; always store the currency next to the amount.

## Supported currencies

```php
Billing::supportedCurrencies('stripe');        // ['AED', ..., 'UAH', 'USD', ...]
Billing::gateways()['stripe']['currencies'];    // same list
```

| Gateway | Built-in list |
|---|---|
| Monobank | UAH, USD, EUR |
| LiqPay | UAH, USD, EUR |
| WayForPay | UAH, USD, EUR |
| Hutko | UAH, USD, EUR, PLN, CZK, GBP |
| Stripe | Stripe's presentment currencies minus zero/three-decimal ones and ISK |
| Paddle | USD, EUR, GBP, AUD, CAD, CHF, HKD, SGD, SEK, ARS, BRL, CNY, COP, CZK, DKK, HUF, ILS, INR, MXN, NOK, NZD, PEN, PLN, RUB, THB, TRY, TWD, UAH, ZAR |

The lists are approximations — no gateway exposes "my currencies", and availability depends on your merchant account. Replace a list per gateway:

```php
'gateways' => [
    'stripe' => [
        // ...credentials
        'currencies' => ['UAH', 'USD', 'EUR'],
    ],
],
```

The override feeds `supportedCurrencies()`, `gateways()` and `resolveChargeAmount()`. `charge()` itself doesn't check it — a driver rejects a currency it can't map (Monobank throws `BillingException`), others leave it to the gateway.

## Currency resolution

`Billing::resolveChargeAmount(Price $price, string $gateway): ResolvedAmount` — used by renewals and provider subscription checkouts — picks the money for a price on a gateway:

1. the price's own currency, if the gateway accepts it;
2. a **sibling** price of the same plan in an accepted currency — same `interval`, `interval_count`, `pricing_type`, `is_active` — one pinned to this gateway first, a generic one (`gateway` null) next;
3. a bound `CurrencyConverterContract` converts to the gateway's first supported currency;
4. otherwise `BillingException`.

`ResolvedAmount` carries `money` (per unit) and, when converted, `convertedFromCurrency`, `exchangeRate`, `exchangeRateAt` — renewals stamp them on the payment.

```php
use Fomvasss\Billing\Contracts\CurrencyConverterContract;

$this->app->bind(CurrencyConverterContract::class, MyCurrencyConverter::class);
// convert(Money $amount, string $toCurrency, ?DateTimeInterface $at = null): Money
```

An adapter over [`fomvasss/laravel-currency`](https://github.com/fomvasss/laravel-currency) is the intended implementation; it is not a dependency.

## Price in USD, charge in UAH

A `Payment` lives in **one currency — the one money moves in**. Convert before creating the row and record the facts next to it:

```php
$usd = new Money($order->total, 'USD');
$uah = app(CurrencyConverterContract::class)->convert($usd, 'UAH');

$payment = Payment::create([
    'gateway' => 'monobank',
    'amount' => $uah->amount,
    'currency' => 'UAH',
    'converted_from_currency' => 'USD',
    'exchange_rate' => $uah->amount / $usd->amount,
    'exchange_rate_at' => now(),
    // payable / billable ...
]);
```

The checkout, the amount verification, the fee and the fiscal receipt then all agree on the UAH sum, and the USD origin and rate stay on the row for reports.
