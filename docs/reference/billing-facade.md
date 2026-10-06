# Billing facade

`Fomvasss\Billing\Facades\Billing`, alias `Billing`. Resolves the `Fomvasss\Billing\BillingManager` singleton — `app(BillingManager::class)` is the same object.

## Charging

| Method | Returns | Description |
|---|---|---|
| `charge(Payment $payment, ChargeOptions $options = new ChargeOptions())` | `PaymentResult` | Opens a checkout and writes `external_id`, `payment_url`, `payment_url_expires_at`, `initiation`, `raw_response` back; resets a non-paid row to `pending`. See [Payments](../usage/payments.md#charging) |
| `startSubscription(Payment $payment, ChargeOptions $options = new ChargeOptions())` | `PaymentResult` | Same, for the first payment of a provider-managed subscription. `$payment->payable` must be the `Subscription`. See [Provider-managed subscriptions](../usage/provider-managed.md) |
| `chargeWithMethod(Payment $payment, PaymentMethod $method, ChargeOptions $options = new ChargeOptions())` | `PaymentResult` | Off-session charge with a saved card; the method must belong to the payment's gateway and billable. See [Saved cards](../usage/saved-cards.md#charging-a-saved-card) |
| `refund(Payment $payment, ?Money $amount = null)` | `Payment` (the refund row) | Full remainder by default. See [Refunds](../usage/refunds.md) |
| `resolveChargeAmount(Price $price, string $gateway)` | `ResolvedAmount` | Per-unit money for a price on a gateway. See [Currency resolution](../usage/money.md#currency-resolution) |

## Gateways

| Method | Returns | Description |
|---|---|---|
| `extend(string $name, string $class)` | `BillingManager` | Register a driver class under a name. `fake` outside `local`/`testing` throws |
| `registerWebhook(string $name, string $validator, ?string $responder = null)` | `BillingManager` | Register the signature validator (and optional responder) for a name |
| `driver(string $name, ?string $tenantId = null)` | `PaymentGatewayContract` | A fresh driver with resolved credentials. Unknown name → `BillingException` |
| `gateways()` | `array` | Metadata of every registered gateway, see [Gateways overview](../usage/gateways.md#gateway-metadata-for-a-settings-ui) |
| `gateway(string $name)` | `?array` | One entry of `gateways()` |
| `supportedCurrencies(string $gateway)` | `array` | Config override or the driver's list |
| `gatewaysImplementing(string $contract)` | `list<string>` | Names whose driver implements a contract |
| `health(string $gateway, ?string $tenantId = null)` | `GatewayHealth` | Live probe; `NotSupportedException` without `ChecksGatewayHealth` |
| `signatureValidatorFor(string $name)`, `responderFor(string $name)` | `string` | Registered class names (used by the webhook controller) |

## Invoices

| Method | Returns | Description |
|---|---|---|
| `issueInvoice(Payment $payment, ?BillingDetails $buyer = null, array $extra = [], ?DateTimeInterface $dueAt = null, ?string $locale = null, ?BillingDetails $seller = null)` | `Invoice` | Idempotent per payment |
| `issueReceipt(Payment $payment, ?BillingDetails $buyer = null, array $extra = [], ?string $locale = null, ?BillingDetails $seller = null)` | `Invoice` | Paid charges only; settles the payment's invoice |
| `voidInvoice(Invoice $invoice)` | `Invoice` | Throws on a paid one |
| `invoiceDocument(Invoice $invoice)` | `InvoiceDocument` | The formatted snapshot |
| `renderInvoice(Invoice $invoice)` | `string` | HTML in the document's locale |
| `invoicePdf(Invoice $invoice)` | `string` | PDF bytes (stored copy if `storage.disk`) |
| `invoicePdfUrl(Invoice $invoice, ?int $ttlMinutes = null)` | `string` | Temporary signed URL; throws when `pdf_route` is off |

See [Invoices and receipts](../usage/invoices.md).

## Exceptions

| Exception | When |
|---|---|
| `Fomvasss\Billing\Exceptions\BillingException` | Configuration and business errors: unknown gateway, missing return URL or credential, unsupported currency, basket not matching the amount, refund guards, a gateway refusing a refund or a subscription change, wrong card for the payment |
| `Fomvasss\Billing\Exceptions\NotSupportedException` | The driver lacks the capability (refunds, tokenization, provider subscriptions, health), or the provider can't do the operation (Stripe pause) |
| `Illuminate\Http\Client\RequestException` | A gateway HTTP error the driver didn't translate |

Both package exceptions extend `RuntimeException`.
