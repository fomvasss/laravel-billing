# Laravel Billing

Billing and payments for Laravel: pluggable payment gateways, one-off payments, saved cards, subscriptions with trials and dunning, usage-based pricing, refunds, invoices and receipts, and a webhook pipeline that is the only thing allowed to change a payment's status.

Built-in gateways: **Monobank Acquiring**, **LiqPay**, **WayForPay**, **Hutko**, **Stripe**, **Paddle**, plus a `fake` gateway for local development. A gateway of your own is one `Billing::extend()` call.

- **One payment model for every gateway** — a `Payment` row is created by your app, charged through any gateway, and settled by the gateway's webhook (or by status polling when the webhook is lost)
- **A plain checkout link everywhere** — `payment_url` is always a redirectable URL, even for form-only gateways; a permanent pay link re-issues an expired checkout on the fly
- **Saved cards** — the card is tokenized as a side effect of the first charge, then charged off-session
- **Subscriptions** — flat, per-seat and metered prices, minute-to-year intervals, trials, pause/resume, plan swaps, quotas, a retry ladder with a grace window; renewals run by the package or by the provider (Stripe Billing, Paddle)
- **Refunds** — full and partial through the API, plus refunds issued from a gateway's dashboard, recorded from webhooks
- **Invoices and receipts** — numbered PDF documents, snapshots of seller, buyer and items at issue time
- **Webhook guarantees** — fail-closed signatures, amount verification, per-outcome dedup, a paid payment never reverted
- **Multi-tenant** — per-tenant credentials for outgoing calls and incoming webhooks, several accounts of one gateway
- **No dependencies beyond `illuminate/*`**, Octane-safe, MySQL/MariaDB, PostgreSQL and SQLite

## Quick example

```php
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;

$payment = Payment::create([
    'gateway' => 'monobank',
    'amount' => 129900, // minor units — 1 299.00
    'currency' => 'UAH',
    'payable_type' => $order->getMorphClass(),
    'payable_id' => $order->id,
    'billable_type' => $user->getMorphClass(),
    'billable_id' => $user->id,
]);

Billing::charge($payment, new ChargeOptions(description: "Order #{$order->number}"));

return redirect($payment->payment_url);
```

```php
use Fomvasss\Billing\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

Event::listen(function (PaymentSucceeded $event) {
    $event->payment->payable->markAsPaid(); // your Order
});
```

The browser coming back from the gateway proves nothing — fulfil orders from `PaymentSucceeded`, which only the verified webhook pipeline fires.

## Contents

Getting started

1. [Installation](installation.md)
2. [Configuration](configuration.md)

Usage

3. [Payments](usage/payments.md)
4. [Return pages and the pay link](usage/return-pages.md)
5. [Fiscal receipt items](usage/fiscal-receipts.md)
6. [Refunds](usage/refunds.md)
7. [Saved cards](usage/saved-cards.md)
8. [Subscriptions](usage/subscriptions.md)
9. [Trials](usage/trials.md)
10. [Renewals and dunning](usage/renewals.md)
11. [Usage and quotas](usage/usage-quotas.md)
12. [Provider-managed subscriptions](usage/provider-managed.md)
13. [Invoices and receipts](usage/invoices.md)
14. [Webhooks](usage/webhooks.md)
15. [Scheduled commands](usage/scheduling.md)
16. [Tenants and multiple accounts](usage/multi-tenancy.md)
17. [Money and currencies](usage/money.md)
18. [Testing](usage/testing.md)

Gateways

19. [Gateways overview](usage/gateways.md)
20. [Monobank](usage/gateways/monobank.md)
21. [LiqPay](usage/gateways/liqpay.md)
22. [WayForPay](usage/gateways/wayforpay.md)
23. [Hutko](usage/gateways/hutko.md)
24. [Stripe](usage/gateways/stripe.md)
25. [Paddle](usage/gateways/paddle.md)
26. [Fake gateway](usage/gateways/fake.md)

Guides

27. [Production checklist](guides/production.md)
28. [Real-world use cases](guides/use-cases.md)
29. [Architecture](guides/architecture.md)
30. [Writing a gateway](guides/writing-a-gateway.md)
31. [Testing webhooks by hand](guides/webhook-testing.md)

Reference

32. [Billing facade](reference/billing-facade.md)
33. [Models](reference/models.md)
34. [Database tables](reference/database.md)
35. [Events](reference/events.md)
36. [Artisan commands](reference/commands.md)
37. [Contracts](reference/contracts.md)
38. [DTOs and enums](reference/dto.md)
39. [Routes](reference/routes.md)

[Upgrading](upgrading.md)
