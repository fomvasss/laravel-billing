# WayForPay

WayForPay (`secure.wayforpay.com`, `api.wayforpay.com`), gateway name `wayforpay`. Driver `Gateways\WayForPay\WayForPayGateway`.

## Credentials

| Key | Env | Default | Meaning |
|---|---|---|---|
| `merchant_account` | `WAYFORPAY_MERCHANT_ACCOUNT` | — | `merchantAccount` |
| `merchant_domain` | `WAYFORPAY_MERCHANT_DOMAIN` | — | Site domain registered with the merchant account |
| `secret_key` | `WAYFORPAY_SECRET_KEY` | — | HMAC-MD5 key |
| `link_ttl_minutes` | `WAYFORPAY_LINK_TTL_MINUTES` | `1440` | Checkout lifetime, sent as `orderLifetime` |

Currencies: UAH, USD, EUR. Webhooks need no setup — `serviceUrl` goes with every purchase.

## Checkout

`charge()` sends a signed Purchase to `/pay?behavior=offline` and reads back a redirect `url` — no browser form. Amounts are decimal (`100.00`); `orderReference` is the payment id. `productName[]`/`productCount[]`/`productPrice[]` come from the receipt items, or one line named after the description. `returnUrl` is the success return URL — the customer comes back with a **POST**, which the package return route accepts. Without a `url` in the response the driver throws `BillingException`.

`raw` fields are merged under the driver's (they can't change signed fields).

## Webhooks

WayForPay posts the callback as **raw JSON under `Content-Type: application/x-www-form-urlencoded`**; the package reads the raw body. The signature is HMAC-MD5 over `merchantAccount;orderReference;amount;currency;authCode;cardPan;transactionStatus;reasonCode`.

WayForPay requires a signed acknowledgment (`{orderReference, status: "accept", time, signature}`) and re-delivers for up to four days without it — `WayForPayWebhookResponder` sends it. Register it again for any extra WayForPay account (see [Tenants and multiple accounts](../multi-tenancy.md#several-accounts-of-one-gateway)).

| `transactionStatus` | Result |
|---|---|
| `Approved` | `paid` (amount/currency checked), fee from `fee` |
| `Declined` | `failed` |
| `Expired` | `canceled` |
| `Refunded`, `Voided` | A refund, recorded from this reversal's `amount` |
| `Pending`, `InProcessing`, ... | Ignored |

`external_id` stays empty — the payment is looked up by its own id. Status polling: `CHECK_STATUS`.

## Saved cards

No opt-in: `recToken` comes with **every** approved card payment and is always saved, `saveCard` or not. Off-session: `transactionType: CHARGE`, `NON3DS`, signed like a purchase. `attachPaymentMethod($billable, ['rec_token' => ...])` trusts the token. `detachPaymentMethod()` is local only.

## Refunds

No API refunds — `Billing::refund()` throws `NotSupportedException`. Refund in the WayForPay dashboard; the `Refunded`/`Voided` callback is recorded. Its `amount` is that reversal's own sum, so re-deliveries are recognized by `processingDate` — two reversals of the same payment within one second collapse into one row.

## Voiding

Not supported — `Billing::voidPayment()` on a payment with an open checkout throws `NotSupportedException`. `REMOVE_INVOICE` works only for invoices from `CREATE_INVOICE` and answers `1127 Order Not Found` for the Purchase checkout the driver opens; `REFUND` on an unpaid order answers `1126 Illegal Order State` (both checked on the test merchant). The order stays payable until its `orderLifetime` (`link_ttl_minutes`) runs out.

## Health

`CHECK_STATUS` of a nonexistent order: `reasonCode 1127` = credentials accepted; `1113` = invalid signature.
