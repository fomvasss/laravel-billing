# Saved cards

A saved card is a `PaymentMethod` row (table `billing_payment_methods`): the gateway's token (`external_id`), the gateway-side customer (`external_customer_id`), `brand`, `last4`, `expires_at` where the gateway reports it, and `is_default` per billable and gateway.

Monobank, LiqPay, WayForPay, Hutko and Stripe implement `TokenizesPaymentMethod`. Paddle doesn't — as a Merchant of Record it can't charge a saved method outside its own subscription.

Needs the `billing-migrations-payment-methods` group.

## Saving a card with the first charge

The main path on every gateway, with no frontend code: the card is saved as a side effect of a real charge, and the `PaymentMethod` appears once the customer pays.

```php
Billing::charge($payment, new ChargeOptions(saveCard: true));
// customer pays → PaymentMethod row + PaymentMethodAttached, nothing else to call
```

| Gateway | How the token arrives |
|---|---|
| Monobank | `saveCardData` on the invoice; the token arrives in a later delivery of the same invoice webhook (`walletData.status = created`) |
| LiqPay | `recurringbytoken`; `card_token` in the paid callback |
| WayForPay | **Always** — `recToken` comes with any approved card payment, `saveCard` or not |
| Hutko | `required_rectoken: Y`; `rectoken` in the paid callback (empty without the flag) |
| Stripe | Hosted Checkout with a per-billable Stripe customer and `setup_future_usage: off_session`; the payment method is pulled from the session's PaymentIntent |

The newly saved card becomes the default for that billable and gateway; the previous default is demoted. A re-delivered webhook for an old card doesn't steal the default back. `PaymentMethodAttached` fires once per new row.

## Charging a saved card

```php
$method = $organization->defaultPaymentMethodFor('monobank');

Billing::chargeWithMethod($payment, $method, new ChargeOptions(customerIp: $user->last_ip));
```

- The method must belong to the payment's gateway **and** billable, or `BillingException` — a cheap guard against debiting the wrong card.
- `chargeWithMethod()` only **initiates** the charge. The outcome arrives through the webhook, same as `charge()`. A synchronous decline (Stripe `card_error`, Hutko/WayForPay error responses) is returned in `PaymentResult::$raw` and stored in `raw_response` — no webhook will carry it.
- `initiation` defaults to `automatic`.
- Receipt items auto-fill from a `HasReceiptItems` payable, as with `charge()`.
- Pass `customerIp`: LiqPay documents it as required for a `paytoken` charge, Hutko sends it as `client_ip`. Both fall back to `127.0.0.1`.

| Gateway | Off-session call |
|---|---|
| Monobank | `POST /wallet/payment`, `initiationKind: merchant` |
| LiqPay | `action: paytoken` |
| WayForPay | `transactionType: CHARGE`, NON3DS |
| Hutko | `POST /api/recurring` |
| Stripe | PaymentIntent `off_session`, `confirm`, idempotency key `charge-{payment id}` |

None of these is retried on a timeout — a timeout doesn't say whether the bank already debited the card. Stripe's idempotency key additionally makes it safe for you to retry the same payment row: Stripe returns the original PaymentIntent instead of a second debit.

## Saving a card without charging — Stripe

Only Stripe can save a card with no payment, through a SetupIntent your frontend confirms with Stripe.js:

```php
$customerId = Billing::driver('stripe')->createCustomer($user);

// frontend: Stripe.js confirms a SetupIntent for $customerId, returns pm_...
$method = Billing::driver('stripe')->attachPaymentMethod($user, ['payment_method_id' => $pmId]);
```

`attachPaymentMethod()` also sets it as the customer's default on Stripe's side, and dispatches `PaymentMethodAttached`.

## Attaching a token you already have

`attachPaymentMethod($billable, $token)` exists on every tokenizing driver for a token obtained some other way. The key differs:

| Gateway | `$token` key | Verified against the gateway |
|---|---|---|
| Stripe | `payment_method_id` | Attached to the Stripe customer |
| Monobank | `card_token` | Must exist in the billable's wallet (`GET /wallet`) |
| LiqPay | `card_token` | No — trusted as given |
| WayForPay | `rec_token` | No |
| Hutko | `rectoken` | No |

> [!WARNING]
> `attachPaymentMethod()` and `createCustomer()` store the billable as its class name (`$billable::class`), while payments and the webhook path use the morph class. With a morph map (`Relation::enforceMorphMap()`) a card attached this way doesn't match the billable's payments: `chargeWithMethod()` rejects it as another billable's card and renewals don't find it. Stripe's customer lookup has the same mismatch and may create a new Stripe customer per saving checkout. Without a morph map both are the same string.

`createCustomer($billable)` returns the gateway-side customer id. Only Stripe creates a real object; the others derive a stable id from the billable (Monobank's `walletId`).

## Removing a card

```php
Billing::driver($method->gateway)->detachPaymentMethod($method);
```

Deletes the row and fires `PaymentMethodDetached`. Monobank (`DELETE /wallet/card`) and Stripe (`/detach`) also revoke the token at the provider; LiqPay, WayForPay and Hutko document no revocation endpoint — the token is only forgotten locally.

## Expiring cards

`expires_at` is filled where the gateway reports it — Stripe does, the Ukrainian gateways' callbacks don't. A renewal treats an expired default card as no card at all (dunning without a gateway call). For Stripe a monthly scan works:

```php
PaymentMethod::where('gateway', 'stripe')->where('expires_at', '<', now()->addMonth())->get();
```

For the rest, the first failed renewal is the signal, and the grace window keeps access while the customer updates the card — a new charge with `saveCard: true` against the same subscription, see [Renewals → Updating the card](renewals.md#updating-the-card).
