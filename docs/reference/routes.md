# Routes

| Name | Method, path | Middleware | Purpose |
|---|---|---|---|
| `billing.webhook` | POST `billing/webhooks/{gateway}` (`webhook.path`) | `webhook.middleware` (none) | The webhook endpoint of every gateway. Unknown gateway 404, bad signature 403 |
| `billing.return` | GET, POST `billing/return/{payment}/{outcome}` | bindings only, no CSRF | Browser return from checkout; fires `CheckoutReturned`, 303 to `return_urls.{outcome}`. `outcome` is `success` or `failed` |
| `billing.pay` | GET `billing/pay/{payment}` | bindings | Permanent pay link |
| `billing.checkout-form` | GET `billing/checkout/{payment}` | `web` | Auto-submits a cached form (LiqPay); 404 once expired |
| `billing.paddle.checkout` | GET `billing/paddle/checkout` | none | Paddle's default payment link page (`?_ptxn=`) |
| `billing.invoices.pdf` | GET `billing/invoices/{invoice}/pdf` | `pdf_middleware`, signed URL | Only with `invoices.enabled` and `pdf_route` |
| `billing.invoices.preview` | GET `billing/invoices/{invoice}/preview` | bindings | HTML preview; `invoices.enabled`, `local`/`testing` only |
| `billing.fake.show` | GET `billing/fake/{payment}` | `web` | Fake gateway checkout; `local`/`testing` only |

Only the webhook path is configurable.
