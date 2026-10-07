# Gateways overview

| Gateway | Name | Checkout | Refunds | Status polling | Saved cards | Provider subscriptions | Webhook setup |
|---|---|---|---|---|---|---|---|
| [Monobank Acquiring](gateways/monobank.md) | `monobank` | Hosted page (`url`) | ✓ | ✓ | ✓ | — | none |
| [LiqPay](gateways/liqpay.md) | `liqpay` | Signed form → package page | ✓ | ✓ | ✓ | — | none |
| [WayForPay](gateways/wayforpay.md) | `wayforpay` | Hosted page (`url`) | — | ✓ | ✓ (always) | — | none |
| [Hutko](gateways/hutko.md) | `hutko` | Hosted page (`url`) | ✓ | ✓ | ✓ | — | none |
| [Stripe](gateways/stripe.md) | `stripe` | Hosted Checkout (`url`) | ✓ | ✓ | ✓ | ✓ | register once |
| [Paddle](gateways/paddle.md) | `paddle` | Paddle.js on your page | ✓ (with approval) | ✓ | — | ✓ | register once + dashboard |
| [Fake](gateways/fake.md) | `fake` | Local two-button page | — | — | — | — | none, `local`/`testing` only |

All of them implement the health check.

## Gateway metadata for a settings UI

```php
use Fomvasss\Billing\Facades\Billing;

Billing::gateways();
// [
//     'monobank' => [
//         'key' => 'monobank',
//         'label' => 'Monobank Acquiring',
//         'currencies' => ['UAH', 'USD', 'EUR'],
//         'credential_fields' => [['name' => 'token', 'type' => 'text', 'secret' => true, 'help' => '...'], ...],
//         'webhook_url' => 'https://example.com/billing/webhooks/monobank',
//         'webhook_requires_dashboard_setup' => false,
//         'capabilities' => ['refunds' => true, 'subscriptions' => false, 'tokenization' => true, 'health' => true],
//     ],
//     ...
// ]

Billing::gateway('monobank');               // one entry, or null when not registered
MonobankGateway::credentialFields();        // static — no credentials or instance needed
```

`credential_fields` entries: `name` (the config key), `type` (`text`/`number`), `secret` (mask it in a UI), `help` (where to get the value; Ukrainian in the built-in drivers). `capabilities.subscriptions` means provider-managed subscriptions — every gateway with tokenization supports package-managed ones.

## Health check

A live, side-effect-free probe — "do the credentials work and is the API up right now":

```php
Billing::health('monobank');
// GatewayHealth { ok: true, message: 'My Shop LLC', latencyMs: 179.2 }
```

```bash
php artisan billing:health            # table of every configured health-capable gateway, exit 1 if any is down
php artisan billing:health monobank
```

| Gateway | Probe | "Up" means |
|---|---|---|
| Monobank | `GET /api/merchant/details` | merchant name returned |
| LiqPay | status of a nonexistent order | `err_code: payment_not_found` |
| WayForPay | `CHECK_STATUS` of a nonexistent order | `reasonCode 1127` (order not found) |
| Hutko | `status/order_id` of a nonexistent order | `error_code 1018` |
| Stripe | `GET /v1/balance` | `livemode: yes/no` |
| Paddle | `GET /event-types` | `live` / `sandbox` |
| Fake | — | always up |

A probe never throws — a failure (including missing credentials) becomes `ok: false` with the reason.

> [!NOTE]
> `billing:health` without an argument skips gateways with none of their secret credentials set (`credential_fields` with `secret: true`, read through the credential resolver without a tenant) and lists them as "not configured". With per-tenant credentials only, every gateway is skipped — name it: `billing:health monobank`. Before 0.12.6 unconfigured built-ins reported DOWN and the command exited 1.

## Choosing a gateway per price

`Price::$gateway` pins a price to one gateway; `null` makes it generic. `resolveChargeAmount()` prefers a gateway-specific sibling in an accepted currency, then a generic one — see [Money and currencies](money.md#currency-resolution).

## Adding a gateway

```php
Billing::extend('acmepay', AcmePayGateway::class)
    ->registerWebhook('acmepay', AcmePaySignatureValidator::class);
```

See [Writing a gateway](../guides/writing-a-gateway.md).
