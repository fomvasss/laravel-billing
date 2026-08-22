# Writing your own gateway

Everything needed to add a payment gateway this package doesn't ship with — in your own app, or as a satellite package (`fomvasss/laravel-billing-yourgateway`). No core changes required either way.

The five built-in drivers (`src/Gateways/`) are the reference implementations; each one is a real, verified integration, so reading the closest match to your gateway is usually faster than starting from scratch.

## Before you write code: verify against the official source

The single most expensive class of bug here is a wrong signature formula or a misread field name, and it's almost always caused by trusting the wrong source. Two real examples from building this package's own drivers:

- **LiqPay's own docs contradict themselves.** The prose on the token-payment page says the signature is `sha3-256`; every code sample on that *same page* (bash, php, java, python, ruby, go, ...) uses `sha1`. The SDK confirms `sha1`. We hit this twice, from two different pages, months apart.
- **Hutko looked like it had no tokenization at all.** The official WooCommerce plugin — which is what we used as the reference, since the docs site doesn't render for a plain fetch — has no card-attach call anywhere. Conclusion drawn: "no tokenization". Wrong: it's in the docs, as `rectoken` in the shared response schema. "The reference implementation doesn't show X" only proves X isn't in *that* reference.

So, in order of trustworthiness: **official SDK source > official docs code samples > official docs prose > someone else's integration**. When two disagree, the SDK wins.

Practical note: several Ukrainian gateway doc sites are JS-rendered and return an empty shell to `curl`/fetch. `https://r.jina.ai/<url>` renders them; `curl` against the SDK's GitHub raw files works for the rest.

## The required contract

Four methods, `Fomvasss\Billing\Contracts\PaymentGatewayContract`. That's the whole entry ticket:

```php
use Fomvasss\Billing\Contracts\PaymentGatewayContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

class AcmePayGateway implements PaymentGatewayContract
{
    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult;
    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult;

    public static function label(): string;              // human name for a settings UI
    public static function credentialFields(): array;    // field schema, see below
    public static function supportedCurrencies(): array; // ISO codes this gateway accepts
    public static function requiresDashboardWebhook(): bool; // true only if webhooks must be registered in the gateway's dashboard (Stripe-style)
}
```

Extend `AbstractGateway` instead of implementing the interface directly and you get a constructor (`$credentials`, `$gatewayName`), a debug logger, a `requiresDashboardWebhook()` default (`false` — most gateways take the callback URL per charge request), and the `webhookUrl()`/`successUrl()`/`failUrl()`/`linkTtlMinutes()`/`persistPaymentMethod()`/`paidAmountMismatch()`/`feeFrom()`/`findPaymentByReference()` helpers. Both paths are equally supported — the base class just saves boilerplate.

### `charge()`

Create the checkout on the gateway's side and return where to send the customer:

```php
public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
{
    $data = $this->http()->post('/checkout', [
        'amount' => $payment->amount,              // ALWAYS minor units — see "Money" below
        'currency' => $payment->currency,
        'reference' => (string) $payment->id,      // so the webhook can find this row again
        'callback_url' => $this->webhookUrl($options),
        'return_url' => $this->successUrl($options),
        'description' => $options->description,
    ])->throw()->json();

    return new PaymentResult(
        url: $data['checkout_url'],
        expiresAt: now()->addMinutes($this->linkTtlMinutes(60)), // see "Checkout-link TTL" below
        externalId: $data['id'],            // written back onto $payment->external_id
    );
}
```

**Checkout-link TTL.** Fill `expiresAt` with a real value whenever you possibly can — `hasActivePaymentUrl()` and the permanent pay link's re-issue logic (`billing.pay`) rest on it; a `null` means "unknown", which the package has to treat as "still alive", so a customer can be redirected to a checkout the gateway already killed. The convention the built-in drivers follow:

- If the gateway accepts a lifetime parameter in the request (Monobank `validity`, WayForPay `orderLifetime`, Hutko `lifetime`) — send it, sourced from `AbstractGateway::linkTtlMinutes($default)` (reads `link_ttl_minutes` from the driver's credentials), and mirror the same value into `expiresAt`. Add `link_ttl_minutes` to your `credentialFields()` so it's configurable.
- If the gateway reports the expiry itself (Stripe's session `expires_at`) — pass that through.
- Only fall back to `expiresAt: null` when the gateway genuinely gives you no way to set *or* learn the lifetime.

`$payment` is passed rather than a bare amount because the driver needs `$payment->id` as the merchant reference it hands the gateway — that's what makes the later webhook resolvable back to this row.

**`url` vs `form`.** Most gateways have a server-side "create checkout, get a link" call — return `url`. A few only accept a browser-submitted POST with signed fields and have no such call (LiqPay is the one built-in example) — return `form: ['action' => ..., 'fields' => [...]]` instead. `BillingManager::charge()` handles both, and either way `$payment->payment_url` ends up a plain redirectable link, so consumers never branch on which kind you returned.

**Money is always minor units** in `$payment->amount` (kopiykas/cents), package-wide. If your gateway wants decimal major units, convert inside the driver (`number_format($minorUnits / 100, 2, '.', '')`), never push that onto the caller.

### `handleWebhook()`

Runs queued, long after the HTTP request is gone — you get the stored payload, not a live `Request`. Signature verification already happened synchronously (see below), so by this point the payload is trusted.

```php
public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
{
    $payload = $webhookCall->payload;

    // A callback for a payment this package didn't create (another integration on the same
    // merchant account) is Ignored, not a failed job.
    $payment = $this->findPaymentByReference($payload['reference'] ?? null);

    if ($payment === null) {
        return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
    }

    $status = match ($payload['status'] ?? null) {
        'approved' => PaymentStatus::Paid,
        'declined' => PaymentStatus::Failed,
        'expired' => PaymentStatus::Canceled,
        default => null, // intermediate/unknown — not a terminal state
    };

    // A signed "paid" callback whose sum doesn't match this row (a stale checkout link paid after
    // the amount was edited) must not mark it paid. AbstractGateway ships this helper.
    if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
        $payment,
        isset($payload['amount']) ? (int) $payload['amount'] : null, // convert to minor units if your gateway sends decimals
        $payload['currency'] ?? null,
    )) {
        return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
    }

    if ($status === null) {
        return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
    }

    // transitionTo(), not update(): it refuses to move an already-paid row to any other status,
    // so a late/out-of-order delivery can't revert a paid payment. It returns false when it
    // refuses — report that delivery as Ignored.
    if (! $payment->transitionTo($status, [
        'external_id' => $payload['id'],
        // Optional: if the callback reports the gateway's commission, record it. feeFrom()
        // returns ['fee' => <minor units>] when the value is numeric, [] otherwise — an
        // unreported fee stays null ("unknown"), never a guessed 0. Pass decimal: true when
        // your gateway sends decimal amounts.
        ...$this->feeFrom($payload['fee'] ?? null),
    ])) {
        return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
    }

    return new WebhookResult(
        type: WebhookEventType::Payment,
        status: $status === PaymentStatus::Paid ? 'succeeded' : 'failed',
        payment: $payment,
        externalId: $payload['id'],   // part of the dedup key, see below
        raw: $payload,
    );
}
```

Things worth getting right:

- **Update the `Payment` yourself, through `transitionTo()`.** The dispatcher fires events from your `WebhookResult`, but it doesn't write the status — the driver does. Use `Payment::transitionTo($status, $attributes)` rather than a bare `update()`: gateway deliveries are neither ordered nor unique, and it enforces the one invariant that matters — a `paid` row is never moved to `failed`/`canceled` by a late callback. It writes nothing and returns `false` when it refuses, which is your cue to return an `Ignored` result.
- **`externalId` feeds the dedup key.** Return the gateway's own reference for this event; the pipeline combines it with the event's type+status (`WebhookResult::dedupKey()`) and claims it against a `unique(name, external_id)` index on `billing_webhook_calls`. Net effect: a re-delivered "paid" callback never fires `PaymentSucceeded` twice, but "declined, then the customer retried the same checkout and paid" — the same reference with a *different* outcome — dispatches both events. You don't have to make the reference unique per attempt.
- **Unknown/intermediate statuses are `Ignored`, not errors.** Gateways send more states than the package's four (`pending`/`paid`/`failed`/`canceled`); anything non-terminal just means "nothing to do yet".
- **Verify the paid amount** (`paidAmountMismatch()`, shown above) whenever the callback carries one.
- **Unknown references are `Ignored` too** — `AbstractGateway::findPaymentByReference()`, never `findOrFail()` or a bare `Payment::find()`: your merchant account may serve other integrations, and their callbacks must not become failed jobs. The helper also guards the reference against the uuid PK — on PostgreSQL a bare `find('their-order-123')` throws a cast error instead of returning null.

### `credentialFields()`

The field schema an admin settings UI renders, and the same keys the default resolver reads from `config('billing.gateways.acmepay.*')`:

```php
public static function credentialFields(): array
{
    return [
        ['name' => 'api_key', 'type' => 'text', 'secret' => true, 'help' => 'API key from the AcmePay dashboard'],
        ['name' => 'merchant_id', 'type' => 'text', 'secret' => false, 'help' => 'Merchant ID'],
    ];
}
```

Read credentials via `$this->credentials['api_key']` — never `config()` directly inside the driver, or you break per-tenant credential resolution.

## Signature validation

A separate class, run synchronously in `WebhookController` **before** the call is stored or queued:

```php
use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Contracts\SignatureValidator;

class AcmePaySignatureValidator implements SignatureValidator
{
    public function isValid(Request $request): bool
    {
        $secret = app(CredentialResolverContract::class)->resolve('acmepay', null)['api_key'] ?? null;

        // Fail closed: the webhook route exists even when the gateway isn't configured — with no
        // key an attacker could compute the "signature" themselves.
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $request->header('X-Signature', ''));
    }
}
```

Things the built-in drivers learned the hard way:

- **Fail closed on a missing secret** — return `false`, never verify against an empty string.
- **Use `hash_equals()`**, not `===`, for signature comparison.
- **Read fields through `Support\WebhookPayload::fromRequest()`**, not `$request->input()`/`all()`, if your signature covers body fields rather than the raw content. Two real reasons: WayForPay POSTs raw JSON under a *form* content type (the framework-parsed body is one garbled key), and `$request->all()` merges query-string extras (the package's `webhookUrlParams` routing hints) into payload-wide signature schemes like Hutko's.
- **If the gateway publishes a rotating key, cache it and retry once on failure** rather than refetching per webhook — and throttle the refetch so a flood of garbage signatures can't hammer the gateway's API. `MonobankSignatureValidator` is the worked example.
- **Resolve the secret via `CredentialResolverContract`** (with `tenantId: null`), not `config()` directly — so a host that binds its own resolver keeps webhook verification working.

Note the asymmetry: this class runs before the payload is trusted, so it can't look up per-tenant credentials keyed by anything *in* the payload. A genuinely multi-merchant setup needs a per-tenant webhook URL — see the note in `MonobankSignatureValidator`.

## Registration

One call, in your `ServiceProvider::boot()`:

```php
use Fomvasss\Billing\Facades\Billing;

Billing::extend('acmepay', AcmePayGateway::class)
    ->registerWebhook('acmepay', AcmePaySignatureValidator::class);
```

That's it — no route to declare, no config file to touch. Every gateway shares one wildcard route (`POST /billing/webhooks/{gateway}`), resolved through this registry at request time.

A class name, not a closure, so the package can call `label()`/`credentialFields()`/`supportedCurrencies()` statically — a settings UI can list your gateway before any credentials exist.

### Custom webhook acknowledgment

Most gateways accept a bare `200`. If yours needs a specific body, pass a third argument:

```php
Billing::extend('acmepay', AcmePayGateway::class)
    ->registerWebhook('acmepay', AcmePaySignatureValidator::class, AcmePayWebhookResponder::class);
```

```php
use Fomvasss\Billing\Contracts\WebhookResponder;

class AcmePayWebhookResponder implements WebhookResponder
{
    public function respond(Request $request): Response
    {
        return response()->json(['received' => true]);
    }
}
```

Worth checking your gateway's docs for this specifically — WayForPay retries a callback for **four days** if it doesn't get back its expected signed acknowledgment, and a bare 200 looks like success from your side while the gateway thinks delivery failed. See `WayForPayWebhookResponder`.

## Optional capabilities

Implement only what your gateway actually does. `BillingManager` checks with `instanceof` and throws a clear `NotSupportedException` otherwise — never a "call to undefined method".

| Contract | Add when the gateway supports |
|---|---|
| `RefundsPayments` | Refunds via API (many require doing it by hand in the dashboard — then don't implement this). Your `refund()` only makes the gateway call — the child `Payment` row and the `PaymentRefunded` event are `BillingManager::refund()`'s job |
| `ChecksPaymentStatus` | Polling a payment's current status — used by `billing:reconcile-pending-payments` as the fallback for a lost webhook |
| `ChecksGatewayHealth` | A live, side-effect-free credentials/reachability probe (`Billing::health()`, `billing:health`). No introspection endpoint? Probe with the status of a nonexistent payment and tell "order not found" (credentials fine) apart from "invalid signature" — verify the discriminating error codes against the live API, the built-in drivers document theirs |
| `TokenizesPaymentMethod` | Saved cards / off-session recurring charges |
| `SubscriptionGatewayContract` | Native subscriptions on the gateway's own side (Stripe-style) |
| `HasReceiptItems` | (on your `Payable`, not the driver) fiscal basket line items — auto-fills into `charge()` AND `chargePaymentMethod()` alike, so build the basket in `chargePaymentMethod()` too if the gateway has one (`array_filter`s the basket key away when `$options->receiptItems` is empty, same as `charge()`) |

### `SubscriptionGatewayContract`

For gateways that host the subscription lifecycle themselves (Stripe Billing). The one hard rule: **your `createSubscription()` must store the provider's subscription reference in `subscriptions.external_id`** — that column is the ownership marker (`Subscription::isProviderManaged()`), and every package scheduler (recurring charges, cancellation finalizing, trial expiry and notices) skips rows where it's set, on the assumption the provider renews/duns/converts and your `handleWebhook()` maps its callbacks (`renewed`, `payment_failed`, `canceled`, `trial_will_end`) to the normal subscription events. Leave `external_id` null and the package will race the provider with its own charges. The split is per subscription, not per driver — the same gateway can serve package-managed subscriptions in parallel.

### `ChecksPaymentStatus`

Cheap to add and genuinely valuable — without it, a payment whose webhook got lost stays `pending` forever. **Update the `Payment` inside `checkStatus()`**, same as `handleWebhook()` does; returning a `WebhookResult` without persisting is a bug we shipped once and had to fix across four drivers at the same time.

### `ChecksGatewayHealth`

One method — a live, **side-effect-free** probe answering "do the configured credentials work and is the API up". It powers `Billing::health()`, the `billing:health` command and the `capabilities.health` flag a settings UI reads to show a "test connection" button. Wrap the probe in `AbstractGateway::probeHealth()` — it times the call and turns any throw into a `down()` result, so a health check itself can never explode:

```php
public function healthCheck(): GatewayHealth
{
    return $this->probeHealth(function () {
        // Best case: the gateway has an introspection endpoint (Stripe /v1/balance,
        // Monobank /api/merchant/details) — call it, return an "up" detail message:
        $data = $this->http()->retry(1)->get('/merchant/info')->throw()->json();

        return $data['merchant_name'] ?? null;
    });
}
```

No introspection endpoint? Probe with the **status of a nonexistent payment** and discriminate by the error: an "order not found"-shaped response proves the request was authenticated and understood (credentials fine → return a message), an "invalid signature"-shaped one proves it wasn't (→ throw with the gateway's reason). Two hard rules: the probe must never create anything on the gateway's side, and the discriminating error codes must be verified against the **live API**, not assumed from docs — the built-in LiqPay/WayForPay/Hutko drivers document their live-verified pairs (`payment_not_found` vs `invalid_signature`, `1127` vs `1113`, `1018` vs `1014`) as worked examples.

### `TokenizesPaymentMethod`

Three shapes exist in the wild, and which one you get decides how you write it:

1. **Synchronous frontend token** (Stripe's SetupIntent path): the frontend SDK hands your backend a token, `attachPaymentMethod()` persists it directly and dispatches `PaymentMethodAttached` itself. (Stripe *also* supports the no-frontend shape — hosted checkout with `setup_future_usage`, the method pulled off the session's intent inside `handleWebhook()` — see `StripeGateway::attachFromCheckoutSession()` for the worked example of a webhook-side attach that needs a follow-up API call.)
2. **Async, separate webhook delivery** (Monobank): the token arrives in its own webhook, distinct from the payment-status one. Return `WebhookResult(type: PaymentMethod, status: 'attached')` from `handleWebhook()` and let `WebhookResultDispatcher` fire the event.
3. **Async, same delivery as the payment status** (LiqPay, WayForPay, Hutko): the token rides along in the payment-status callback. That `WebhookResult` is already reporting the `Payment` outcome, so persist the method as a side effect and dispatch `PaymentMethodAttached` **directly** — there's no second return value for the dispatcher to work with. Guard the dispatch with `$method->wasRecentlyCreated`: a direct dispatch runs before the job-level dedup claim, so without the guard a re-delivered callback fires the event again.

Use `AbstractGateway::persistPaymentMethod()` for the actual row-writing in all three cases; it demotes the previous default and upserts on `(gateway, external_customer_id, external_id)`, deliberately without dispatching, precisely because the three cases dispatch at different times.

## HTTP calls

Always bounded, never a bare `Http::post()` — a hung gateway shouldn't hang a queue worker:

```php
protected function http(): PendingRequest
{
    return Http::baseUrl(self::BASE_URL)
        ->withToken($this->credentials['api_key'])
        ->timeout(15)
        ->retry(2, 200);
}
```

One exception worth knowing: **don't retry a charge attempt** unless the gateway supports idempotency keys, or a network blip can double-charge a customer. `StripeGateway::chargePaymentMethod()` overrides the shared retry with `retry(1)` for exactly this reason.

Relatedly, a declined card is a *business outcome*, not a transport failure — return it as a `PaymentResult` rather than throwing, so the caller can record the failure. Only genuine wiring errors (bad credentials, malformed request) should throw.

## Testing without real credentials

You don't need a merchant account to have real confidence. What the built-in drivers do:

```php
Http::fake(['https://api.acmepay.test/checkout' => Http::response(['checkout_url' => 'https://pay.acmepay.test/abc', 'id' => 'tx_1'])]);

$result = Billing::driver('acmepay')->charge($payment);

$this->assertSame('https://pay.acmepay.test/abc', $result->url);
Http::assertSent(fn ($request) => $request['reference'] === (string) $payment->id
    && $request['amount'] === 10000);
```

And for the signature validator, recompute the expected signature independently in the test — don't call the driver's own `sign()` on both sides, or you're only asserting that a function equals itself:

```php
$body = json_encode(['status' => 'approved', 'reference' => $payment->id]);
$signature = hash_hmac('sha256', $body, 'test-key'); // computed here, in the test

$response = $this->call('POST', route('billing.webhook', ['gateway' => 'acmepay']), [], [], [], [
    'HTTP_X-Signature' => $signature,
], $body);

$response->assertOk();
```

Add a tamper case (flip a byte in the body, expect rejection) and, if the gateway has a replay window, a stale-timestamp case.

## Checklist

- [ ] Signature formula verified against the official SDK, not just docs prose
- [ ] `amount` converted if the gateway wants major units
- [ ] `charge()` puts `$payment->id` where the webhook will echo it back
- [ ] If the gateway has a fiscal basket field, `chargePaymentMethod()` builds it from `$options->receiptItems` too, not just `charge()` (skip this if the gateway's off-session API genuinely has no basket concept, like Stripe's PaymentIntents)
- [ ] `expiresAt` filled from a real TTL (`linkTtlMinutes()` + the gateway's lifetime param, or the gateway's own reported expiry) — `null` only when there's truly no way to know
- [ ] `handleWebhook()` updates the `Payment` and returns a real `externalId`
- [ ] Non-terminal gateway statuses and unknown references return `Ignored`, not an error
- [ ] Paid callbacks checked against the payment's amount/currency (`paidAmountMismatch()`)
- [ ] Gateway commission parsed into `fee` where the callback reports it (`feeFrom()`)
- [ ] Credentials read from `$this->credentials`, not `config()`
- [ ] Validator fails closed on a missing secret; `hash_equals()` for comparison; rotating keys cached with one throttled retry
- [ ] Checked whether the gateway needs a specific acknowledgment body
- [ ] HTTP calls have `timeout()`; charge attempts aren't blindly retried
- [ ] Only the capability contracts the gateway actually supports
- [ ] `healthCheck()` (if implemented) is side-effect-free, and its error discriminators are verified against the live API
- [ ] `Http::fake()` tests for `charge()`, independent signature recomputation for the validator
