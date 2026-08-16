# Testing webhooks by hand (Postman / curl / a tunnel)

Automated feature tests should use the `fake` gateway (see the README's Testing section) — it runs the exact real pipeline. This document is for *manual* poking: replaying gateway callbacks from Postman/curl, and receiving real ones locally through a tunnel.

## No signature needed: the `fake` gateway

```
POST /billing/webhooks/fake
Content-Type: application/json

{"payment_id": "<payment uuid>", "result": "success"}
```

`result: "failure"` exercises the failed path. Everything downstream (storage, queue, dedup, events) is the production pipeline.

## Real gateways: compute the signature with your own secret

The HMAC gateways (LiqPay, WayForPay, Hutko, Stripe) verify against a secret you hold, so a valid request is fully forgeable *by you*. Stripe in a Postman pre-request script:

```javascript
const t = Math.floor(Date.now() / 1000); // fresh — there's a 5-minute replay window
const sig = CryptoJS.HmacSHA256(`${t}.${pm.request.body.raw}`, 'whsec_...').toString();
pm.request.headers.add({key: 'Stripe-Signature', value: `t=${t},v1=${sig}`});
```

The signature recipes for every gateway (LiqPay's `base64(sha1(priv.data.priv))`, WayForPay's HMAC-MD5 field list, Hutko's ksort-pipe-sha1) are implemented, with test vectors, in the package's own `tests/Feature/WebhookSignatureValidationTest.php` — copy from there rather than re-deriving.

Delivery-shape quirks to reproduce faithfully:

- **WayForPay** posts **raw JSON under `Content-Type: application/x-www-form-urlencoded`**. In Postman: body → raw (paste the JSON), set the header manually. Sending it as normal JSON also works against this package (the payload parser sniffs the body), but the weird variant is what production delivers.
- **Monobank cannot be faked** — its webhooks are ECDSA-signed with the bank's private key. You can only test the rejection path (any body → 403) or use a real test merchant that sends genuine webhooks (see the tunnel below).

## Gotchas that make a manual test look "broken"

- The `Payment` must exist (status `pending`), and the payload's **amount/currency must match it** — otherwise you get a 200 but the result is `Ignored` (the stale-checkout protection) and nothing changes.
- **A 200 means "accepted and queued"**: locally set `QUEUE_CONNECTION=sync`, or run a worker — a redis queue with no worker looks like nothing happened.
- Repeating the same request changes nothing and fires no second event — that's dedup, not a bug. A *different outcome* for the same reference (failed, then success) does dispatch both.
- A row in `billing_webhook_calls` = the signature passed; a 403 = it didn't (check the secret, and for Stripe the timestamp freshness).

## Receiving real webhooks locally: a tunnel

The gateways can't reach your machine, and — the part everyone forgets — **they're given whatever URL `route()` generates from `APP_URL`**, so starting a tunnel alone changes nothing. Force the public origin app-wide:

```php
// config/app.php
'ngrok_url' => env('APP_NGROK_URL'),

// AppServiceProvider::boot()
if ($ngrokUrl = config('app.ngrok_url')) {
    URL::useOrigin($ngrokUrl);
    URL::forceScheme(parse_url($ngrokUrl, PHP_URL_SCHEME) ?: 'https');
}
```

```bash
ngrok http --domain=your-domain.ngrok-free.app --host-header=rewrite your-app.test:80
```

Then set `APP_NGROK_URL=https://your-domain.ngrok-free.app`, `config:clear`, restart your queue worker — and every `server_url`/`webHookUrl` the drivers send in charge requests points at the tunnel. Diagnostics live in ngrok's inspector (`http://127.0.0.1:4040`): you see each gateway POST and your response (200 accepted / 403 signature / 405 wrong method).

Tunnel-specific traps: a 502 on the tunnel domain means ngrok couldn't reach your local host (typo in the hostname, missing `/etc/hosts` entry) — not an app problem; `--host-header=rewrite` is required when nginx routes by vhost; and browse the demo/checkout through the tunnel domain too, or you'll be bouncing between origins. **Stripe is the exception to "nothing to configure"**: its webhook endpoint is registered on Stripe's side (one `POST /v1/webhook_endpoints` API call returns the `whsec_` secret), so changing the tunnel domain means re-creating the endpoint.
