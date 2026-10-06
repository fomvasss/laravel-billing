# Monobank

Monobank Acquiring (`api.monobank.ua`), gateway name `monobank`. Driver `Gateways\Monobank\MonobankGateway`.

## Credentials

| Key | Env | Default | Meaning |
|---|---|---|---|
| `token` | `MONOBANK_TOKEN` | — | X-Token from the merchant cabinet (web.monobank.ua), or a test token from api.monobank.ua |
| `link_ttl_minutes` | `MONOBANK_LINK_TTL_MINUTES` | `60` | Checkout lifetime, sent as `validity` |

Currencies: UAH, USD, EUR. Webhooks need no setup — `webHookUrl` goes with every invoice.

## Checkout

`charge()` creates an invoice (`POST /api/merchant/invoice/create`):

| Field | From |
|---|---|
| `amount`, `ccy` | Minor units; ISO numeric currency code |
| `merchantPaymInfo.reference` | The payment id — how the webhook finds the row |
| `merchantPaymInfo.destination` | `ChargeOptions::$description` |
| `merchantPaymInfo.customerEmails` | `customerEmail` |
| `merchantPaymInfo.basketOrder` | Receipt items (`name`, `qty`, `sum`, `code`) |
| `redirectUrl` | The success return URL — Monobank has **one** return URL for every outcome; `failUrl` is ignored |
| `validity` | `link_ttl_minutes` × 60 |
| `saveCardData` | With `saveCard`: `saveCard: true` and a `walletId` derived from the billable |

`raw` is merged into the request (e.g. `agentFeePercent`); the driver's fields win. `external_id` = `invoiceId`.

## Webhooks

Signed with ECDSA by the bank (`X-Sign` over the raw body). The validator fetches Monobank's public key with the merchant token, caches it for a week per token, and on a failed check refetches once (throttled to once per 5 minutes) in case the key rotated. If the key can't be fetched, the webhook answers 403 and Monobank re-delivers.

| `status` | Result |
|---|---|
| `success` | `paid` (amount and currency checked), fee from `paymentInfo.fee` |
| `failure` | `failed` |
| `reversed` | A refund, recorded from `cancelList` |
| `created`, `processing`, `hold` | Ignored (not terminal) |

Status polling (`GET /invoice/status`) also maps `expired` → `canceled`.

## Saved cards

`saveCard: true` → the token arrives in a later delivery of the same invoice webhook, with `walletData.status = created`; the driver saves it as a side effect. Off-session charges go to `POST /wallet/payment` with `initiationKind: merchant`, including the basket. `attachPaymentMethod($billable, ['card_token' => ...])` verifies the token is in the billable's wallet (`GET /wallet`). `detachPaymentMethod()` calls `DELETE /wallet/card`.

## Refunds

`POST /invoice/cancel` with an optional amount (partial refunds supported). `status: failure` → `BillingException`; `processing` is accepted. Dashboard reversals arrive as `reversed` and are recorded from the sum of successful `cancelList` entries.

## Health

`GET /api/merchant/details` — returns the merchant name.
