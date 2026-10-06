# Hutko

Hutko (`pay.hutko.org/api`), gateway name `hutko`. Driver `Gateways\Hutko\HutkoGateway`.

## Credentials

| Key | Env | Default | Meaning |
|---|---|---|---|
| `merchant_id` | `HUTKO_MERCHANT_ID` | — | From the merchant portal |
| `secret_key` | `HUTKO_SECRET_KEY` | — | Signs requests and callbacks |
| `link_ttl_minutes` | `HUTKO_LINK_TTL_MINUTES` | `1440` | Checkout lifetime, sent as `lifetime` |

Currencies: UAH, USD, EUR, PLN, CZK, GBP (Hutko converts to UAH itself). Webhooks need no setup — `server_callback_url` goes with every request.

Signature: drop empty fields, `ksort`, prepend the secret, join with `|`, SHA-1. Every request is `{"request": {...}}`; any field you add through `raw` is signed too.

## Checkout

`POST checkout/url`: `order_id` = payment id, `amount` in minor units, `order_desc` (default `Payment #{id}`), `lang`, `sender_email`, `response_url` (success return URL — the customer returns with a **POST**), `reservation_data` (receipt items as Hutko's RRO basket, base64 JSON with decimal prices), `required_rectoken: Y` with `saveCard`, `lifetime`. A `response_status` other than `success` throws `BillingException`.

## Webhooks

| Callback | Result |
|---|---|
| `order_status: approved` | `paid` (amount/currency checked), fee from `fee`, `external_id` = `payment_id` |
| `order_status: declined` | `failed` |
| `order_status: expired` | `canceled` |
| `reversal_amount` set (or `tran_type: reverse`) | A refund, recorded from the order's running `reversal_amount` |
| `created`, `processing` | Ignored |

A reversal is reported by the ordinary purchase callback again (still `approved`) with `reversal_amount` filled in — that is checked first. Status polling: `status/order_id`.

## Saved cards

Opt-in: without `saveCard` (`required_rectoken: Y`) the callback's `rectoken` is empty. Off-session: `POST /api/recurring` with `client_ip` (from `customerIp`, default `127.0.0.1`) and the basket. Only real Hutko fields may go through `raw` here — an unknown key breaks the signature. A decline is returned in `raw`, not thrown. `attachPaymentMethod($billable, ['rectoken' => ...])` trusts the token; `detachPaymentMethod()` is local only.

## Refunds

`POST reverse/order_id`, always with an explicit amount (partial supported). `response_status: failure` or `reverse_status: declined` → `BillingException`; `created` is accepted. The refund row's `external_id` is the reversal's `transaction_id`.

## Health

`status/order_id` of a nonexistent order: `error_code 1018` = credentials accepted; `1014` = invalid signature.
