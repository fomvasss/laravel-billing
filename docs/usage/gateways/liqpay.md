# LiqPay

LiqPay (`liqpay.ua`, API version 3), gateway name `liqpay`. Driver `Gateways\LiqPay\LiqPayGateway`.

## Credentials

| Key | Env | Default | Meaning |
|---|---|---|---|
| `public_key` | `LIQPAY_PUBLIC_KEY` | — | Public key from the LiqPay cabinet |
| `private_key` | `LIQPAY_PRIVATE_KEY` | — | Private key — signs requests and verifies callbacks |
| `link_ttl_minutes` | `LIQPAY_LINK_TTL_MINUTES` | `60` | Lifetime of the package's checkout-form page |

Currencies: UAH, USD, EUR. Webhooks need no setup — `server_url` goes with every payment.

Signature: `base64(sha1(private_key . data . private_key, raw))` — SHA-1, as in LiqPay's SDK (the docs' prose says SHA3-256; their code samples and the SDK use SHA-1).

## Checkout

LiqPay has no "create a checkout, get a link" call — its page accepts only a browser-submitted POST with signed `data`. `charge()` returns `PaymentResult::$form`; `Billing::charge()` caches it and points `payment_url` at `billing/checkout/{payment}`, which auto-submits the form. The signed form itself doesn't expire; `link_ttl_minutes` bounds the cached page.

| Field | From |
|---|---|
| `action` | `pay` |
| `amount` | Decimal major units (`100.00`) |
| `order_id` | The payment id |
| `description` | `ChargeOptions::$description`, default `Payment #{id}` |
| `result_url` | Success return URL — one for every outcome |
| `server_url` | Callback URL |
| `language` | `locale` narrowed to `uk`/`en` |
| `recurringbytoken` | `1` with `saveCard` |

Receipt items are **not** used — fiscalize through `raw` → `rro_info` with your LiqPay goods ids, see [Fiscal receipt items](../fiscal-receipts.md#per-gateway).

`external_id` stays empty until the callback brings LiqPay's `payment_id`.

## Webhooks

| `status` | Result |
|---|---|
| `success`, `sandbox` | `paid` (amount/currency checked), fee from `receiver_commission` |
| `failure`, `error` | `failed` |
| `reversed` | A refund, recorded from `refund_amount` |
| others | Ignored |

> [!NOTE]
> `sandbox` counts as paid — that is how LiqPay test keys report a successful payment. Don't run production on sandbox keys.

Status polling (`action: status`) also maps `expired` → `canceled`.

## Saved cards

`saveCard` sends `recurringbytoken`; `card_token` comes in the paid callback and is saved as a side effect. Off-session: `action: paytoken`, with `ip` from `ChargeOptions::$customerIp` (required by LiqPay; falls back to `127.0.0.1`) and `is_recurring`. `attachPaymentMethod($billable, ['card_token' => ...])` trusts the token. `detachPaymentMethod()` is local only.

## Refunds

`action: refund` with an amount. `result` other than `ok` → `BillingException`. Dashboard refunds arrive as `reversed`; `refund_amount` is read as the order's running total.

## Health

Status of a nonexistent order: `payment_not_found` = credentials accepted.
