# Contracts

Namespace `Fomvasss\Billing\Contracts`.

## Your models

| Contract | Methods | Purpose |
|---|---|---|
| `Billable` | `tenantId(): ?string` | Who pays; the tenant picks credentials. Implemented by the `Concerns\Billable` trait |
| `Payable` | — | Marker for what is paid for |
| `HasReceiptItems` | `receiptItems(): array` | Fiscal basket of a payable, see [Fiscal receipt items](../usage/fiscal-receipts.md) |
| `HasBillingDetails` | `billingDetails(): BillingDetails` | The buyer on invoices |

## Bindings you can replace

Bind in a service provider's `register()`; the defaults are in `Fomvasss\Billing\Support`.

| Contract | Method | Default | Purpose |
|---|---|---|---|
| `CredentialResolverContract` | `resolve(string $gateway, ?string $tenantId): array` | `DefaultCredentialResolver` — `config("billing.gateways.{$gateway}")` | Per-tenant / database credentials |
| `RenewalChargeOptionsContract` | `resolve(Subscription $subscription, Payment $payment): ChargeOptions` | `DefaultRenewalChargeOptions` — empty, or one basket line with `renewal.receipt_items` | Options of scheduled renewals |
| `ReissueChargeOptionsContract` | `resolve(Payment $payment): ChargeOptions` | `DefaultReissueChargeOptions` — empty | Options of pay-link re-issues |
| `CurrencyConverterContract` | `convert(Money $amount, string $toCurrency, ?DateTimeInterface $at = null): Money` | not bound | Currency conversion step of `resolveChargeAmount()` |
| `InvoiceSellerContract` | `seller(?string $gateway, ?string $tenantId): BillingDetails` | `DefaultInvoiceSeller` — gateway `seller` block, else `invoices.seller` | Document issuer |
| `InvoiceItemsContract` | `items(Payment $payment): array` | `DefaultInvoiceItems` — the payable's `receiptItems()` | Document lines |
| `InvoiceTemplateResolver` | `view(Invoice $invoice): string` | `DefaultInvoiceTemplateResolver` — `$invoice->template` or `billing::invoices.{type}` | Template per document |
| `InvoiceViewDataContract` | `data(Invoice $invoice): array` | `DefaultInvoiceViewData` — `[]` | Extra view variables |
| `InvoiceRenderer` | `pdf(string $html): string` | `DompdfInvoiceRenderer` | HTML → PDF |

## Gateway drivers

| Contract | Methods | Capability flag |
|---|---|---|
| `PaymentGatewayContract` (required) | `charge(Payment, ChargeOptions): PaymentResult`, `handleWebhook(BillingWebhookCall): WebhookResult`, static `label()`, `credentialFields()`, `supportedCurrencies()`, `requiresDashboardWebhook()` | — |
| `RefundsPayments` | `refund(Payment, ?Money): PaymentResult` | `refunds` |
| `ChecksPaymentStatus` | `checkStatus(Payment): WebhookResult` | used by reconciliation |
| `ChecksGatewayHealth` | `healthCheck(): GatewayHealth` | `health` |
| `TokenizesPaymentMethod` | `createCustomer(Model&Billable): string`, `attachPaymentMethod(Model&Billable, array $token): PaymentMethod`, `chargePaymentMethod(Payment, PaymentMethod, ChargeOptions): PaymentResult`, `detachPaymentMethod(PaymentMethod): void` | `tokenization` |
| `StartsProviderSubscriptions` | `startSubscription(Payment, ChargeOptions): PaymentResult` | `subscriptions` |
| `ManagesProviderSubscriptions` | `cancel(Subscription, bool $atPeriodEnd)`, `pause(Subscription, ?DateTimeInterface $until)`, `resume(Subscription)`, `swapPrice(Subscription, Price)` — each returns `SubscriptionSnapshot` | `subscriptions` |
| `ReportsTrialEnding` | — (marker) | The provider sends its own trial-ending event; local reminders are skipped |
| `SubscriptionGatewayContract` | **deprecated** — use the two above | `subscriptions` |
| `SignatureValidator` | `isValid(Request): bool` | registered with `registerWebhook()` |
| `WebhookResponder` | `respond(Request): Response` | optional third argument of `registerWebhook()` |

`Gateways\AbstractGateway` implements the boilerplate. See [Writing a gateway](../guides/writing-a-gateway.md).
