# Laravel Billing

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-billing.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-billing)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-billing.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-billing)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-billing.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-billing)

Білінг і оплати для Laravel: підключні платіжні гейтвеї, разові платежі, збережені картки, підписки з тріалом і dunning, тарифікація за споживанням, повернення коштів, рахунки й квитанції — і конвеєр вебхуків, який єдиний має право змінювати статус платежу. Вбудовані гейтвеї: **Monobank Acquiring**, **LiqPay**, **WayForPay**, **Hutko**, **Stripe**, **Paddle**, плюс `fake` для локальної розробки.

[English](README.md)

Документація англійською — https://fomvasss.github.io/laravel-billing/ (ті самі сторінки, що в [docs/](docs/index.md)).

- **Одна модель платежу для всіх гейтвеїв** — `payment_url` завжди звичайне посилання; постійний лінк на оплату перевипускає прострочену касу
- **Збережені картки** — токенізація побічним ефектом першої оплати, далі списання без участі клієнта
- **Підписки** — фіксовані, поштучні й metered ціни, інтервали від хвилини до року, тріали, пауза, зміна тарифу, квоти, повтори списань із grace-доступом; продовжує пакет або провайдер (Stripe Billing, Paddle)
- **Повернення** — через API, а також зроблені в кабінеті гейтвея, записані з вебхуків
- **Рахунки й квитанції** — нумеровані PDF, знімок даних на момент виставлення
- **Гарантії вебхуків** — підписи fail-closed, звірка суми, дедуплікація за результатом, звірка загублених вебхуків
- **Мультитенантність** — креди на тенанта й кілька акаунтів одного гейтвея
- Без залежностей поза `illuminate/*`, сумісний з Octane, MySQL/MariaDB, PostgreSQL, SQLite

## Вимоги

- PHP ^8.3
- Laravel ^12 | ^13

## Встановлення

```bash
composer require fomvasss/laravel-billing

php artisan vendor:publish --tag=billing-migrations-core
php artisan vendor:publish --tag=billing-migrations-subscriptions    # Plan / Price / Subscription
php artisan vendor:publish --tag=billing-migrations-payment-methods  # збережені картки
php artisan vendor:publish --tag=billing-migrations-invoices         # рахунки й квитанції
php artisan migrate
```

```env
BILLING_RETURN_URL_SUCCESS=https://example.com/checkout/success
BILLING_RETURN_URL_FAILED=https://example.com/checkout/failed
BILLING_SCHEDULE_ENABLED=true

MONOBANK_TOKEN=...
```

## Швидкий старт

```php
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;

$payment = Payment::create([
    'gateway' => 'monobank',
    'amount' => 129900, // мінорні одиниці — 1 299.00
    'currency' => 'UAH',
    'payable_type' => $order->getMorphClass(),
    'payable_id' => $order->id,
    'billable_type' => $user->getMorphClass(),
    'billable_id' => $user->id,
]);

Billing::charge($payment, new ChargeOptions(description: "Замовлення #{$order->number}"));

return redirect($payment->payment_url);
```

```php
use Fomvasss\Billing\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $event->payment->payable->markAsPaid();
});
```

## Документація

- [Installation](docs/installation.md) · [Configuration](docs/configuration.md)
- [Payments](docs/usage/payments.md) · [Return pages](docs/usage/return-pages.md) · [Fiscal receipt items](docs/usage/fiscal-receipts.md) · [Refunds](docs/usage/refunds.md) · [Saved cards](docs/usage/saved-cards.md)
- [Subscriptions](docs/usage/subscriptions.md) · [Trials](docs/usage/trials.md) · [Renewals](docs/usage/renewals.md) · [Usage and quotas](docs/usage/usage-quotas.md) · [Provider-managed](docs/usage/provider-managed.md)
- [Invoices](docs/usage/invoices.md) · [Webhooks](docs/usage/webhooks.md) · [Scheduled commands](docs/usage/scheduling.md) · [Tenants](docs/usage/multi-tenancy.md) · [Money](docs/usage/money.md) · [Testing](docs/usage/testing.md)
- Гейтвеї: [Огляд](docs/usage/gateways.md) · [Monobank](docs/usage/gateways/monobank.md) · [LiqPay](docs/usage/gateways/liqpay.md) · [WayForPay](docs/usage/gateways/wayforpay.md) · [Hutko](docs/usage/gateways/hutko.md) · [Stripe](docs/usage/gateways/stripe.md) · [Paddle](docs/usage/gateways/paddle.md) · [Fake](docs/usage/gateways/fake.md)
- Гайди: [Production checklist](docs/guides/production.md) · [Use cases](docs/guides/use-cases.md) · [Architecture](docs/guides/architecture.md) · [Writing a gateway](docs/guides/writing-a-gateway.md) · [Testing webhooks](docs/guides/webhook-testing.md)
- Довідник: [Billing facade](docs/reference/billing-facade.md) · [Models](docs/reference/models.md) · [Database](docs/reference/database.md) · [Events](docs/reference/events.md) · [Commands](docs/reference/commands.md) · [Contracts](docs/reference/contracts.md) · [DTOs and enums](docs/reference/dto.md) · [Routes](docs/reference/routes.md)
- [Upgrading](docs/upgrading.md) · [Changelog](CHANGELOG.md)

## Ліцензія

MIT — дивись [LICENSE](LICENSE.md).
