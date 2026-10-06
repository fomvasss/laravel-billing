# Tenants and multiple accounts

Two independent axes, which combine:

- **Gateway name** — which account type: `liqpay`, `liqpay_shop2`, `stripe_eu`. Each name is its own registered gateway.
- **Tenant** — whose credentials: the billable's `tenantId()`.

A credential resolver receives both: `resolve(string $gateway, ?string $tenantId): array`.

## Per-tenant credentials

The default resolver reads `config("billing.gateways.{$gateway}")` and ignores the tenant. Bind your own to load credentials from wherever you keep them:

```php
use Fomvasss\Billing\Contracts\CredentialResolverContract;

class TenantCredentialResolver implements CredentialResolverContract
{
    public function resolve(string $gateway, ?string $tenantId): array
    {
        if ($tenantId === null) {
            return (array) config("billing.gateways.{$gateway}", []);
        }

        return GatewayAccount::where('tenant_id', $tenantId)->where('gateway', $gateway)->first()?->credentials ?? [];
    }
}

// AppServiceProvider::register()
$this->app->bind(CredentialResolverContract::class, TenantCredentialResolver::class);
```

Return the same keys the driver declares in `credentialFields()` (`token`, `public_key`/`private_key`, ...).

**Outgoing calls** (`charge()`, `refund()`, renewals, subscription management) resolve with the billable's `tenantId()`.

**Incoming webhooks** can't — a webhook must pick a secret before anything in it can be trusted. So the tenant rides in the callback URL: `charge()` adds `?tenant={id}` automatically whenever the billable has a tenant, every built-in validator reads it back (`Support\WebhookTenant::fromRequest()`), and the queued job builds its driver with the same tenant (`WebhookTenant::fromUrl()`). The hint is untrusted by construction and safe anyway: it only selects which secret to verify against — a forged one picks the wrong secret and fails.

**Stripe and Paddle** deliver only to a URL registered in advance, so each tenant's own account needs its own endpoint with the hint baked in:

```bash
php artisan billing:stripe-register-webhook --tenant=acme
php artisan billing:paddle-register-webhook --tenant=acme
```

They register with that tenant's credentials, put `?tenant=acme` on the URL, and print the secret to store for that tenant. Tenants sharing one Stripe/Paddle account need nothing extra.

Custom validators should do the same:

```php
$credentials = app(CredentialResolverContract::class)->resolve($request->route('gateway'), WebhookTenant::fromRequest($request));
```

> [!NOTE]
> Credentials are resolved at call time and never memoized — `BillingManager` is a singleton holding only class-name registries, every `driver()` call builds a fresh instance. That is what makes per-tenant credentials safe under Octane. Register gateways in a provider's `boot()`, not mid-request.

## Several accounts of one gateway

Each account is a gateway name with its own config block. The built-in names are registered by the package; register every extra one for the same driver class:

```php
// config/billing.php
'gateways' => [
    'liqpay_shop2' => [
        'public_key' => env('LIQPAY_SHOP2_PUBLIC_KEY'),
        'private_key' => env('LIQPAY_SHOP2_PRIVATE_KEY'),
    ],
    'stripe_eu' => [
        'secret_key' => env('STRIPE_EU_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_EU_WEBHOOK_SECRET'),
    ],
],
```

```php
// AppServiceProvider::boot()
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Gateways\LiqPay\LiqPayGateway;
use Fomvasss\Billing\Gateways\LiqPay\LiqPaySignatureValidator;
use Fomvasss\Billing\Gateways\Stripe\StripeGateway;
use Fomvasss\Billing\Gateways\Stripe\StripeSignatureValidator;

Billing::extend('liqpay_shop2', LiqPayGateway::class)
    ->registerWebhook('liqpay_shop2', LiqPaySignatureValidator::class);

Billing::extend('stripe_eu', StripeGateway::class)
    ->registerWebhook('stripe_eu', StripeSignatureValidator::class);
```

From there everything follows the name: a payment with `'gateway' => 'liqpay_shop2'` charges with that block, its webhooks arrive at `/billing/webhooks/liqpay_shop2` and are verified with that block's secret, `Billing::gateways()` lists it separately.

- **WayForPay** needs its responder as the third argument: `->registerWebhook('wayforpay_2', WayForPaySignatureValidator::class, WayForPayWebhookResponder::class)` — without the signed acknowledgment WayForPay re-delivers for four days.
- **Stripe and Paddle**: register the extra name's endpoint too — `billing:stripe-register-webhook --gateway=stripe_eu`, `billing:paddle-register-webhook --gateway=paddle_2` (combinable with `--tenant`).
- **Paddle**: point every account's default payment link at the same `/billing/paddle/checkout` — the page finds the payment by its transaction and uses the client token of the name it was charged through. Several sites on *one* Paddle account: one name per site with the same keys and its own `checkout_url`.
- **Accounts added from an admin panel**: bind a resolver that receives the name and returns the credentials — those names need no config block, only the `extend()` call. The names still have to be registered at boot.

Re-registering a built-in name (`Billing::extend('monobank', MyMonobank::class)`) replaces it — last registration wins.
