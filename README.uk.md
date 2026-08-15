# Laravel Billing

Універсальний пакет білінгу/оплат для Laravel: підключні платіжні гейтвеї, одноразові платежі й підписки з тріалом, тарифікація за фактичним споживанням, обробка вебхуків.

[English documentation](README.md)

Вбудовані гейтвеї: **LiqPay**, **WayForPay**, **Monobank Acquiring**, **Stripe**, **Hutko**. Додати власний — один виклик `Billing::extend()`, без правок ядра.

## Вимоги

- PHP ^8.3
- Laravel ^12 | ^13

## Встановлення

```bash
composer require fomvasss/laravel-billing
```

Опублікувати власні міграції пакета, групами, щоб отримати лише ті таблиці, які реально потрібні. `billing-migrations-core` (таблиці `payments` і `webhook_calls`) потрібна всім:

```bash
php artisan vendor:publish --tag=billing-migrations-core
php artisan vendor:publish --tag=billing-migrations-subscriptions    # лише якщо є Plan/Price/Subscription
php artisan vendor:publish --tag=billing-migrations-payment-methods  # лише якщо є збережені картки/токени
php artisan migrate
```

Повторний `vendor:publish` — безпечний: файли копіюються під фіксованими іменами, вже опублікована міграція пропускається, не дублюється.

Опублікувати конфіг, якщо потрібно змінити дефолти (return-URL, дебаг-лог, grace-період тощо):

```bash
php artisan vendor:publish --tag=billing-config
```

## Швидкий старт — гейтвей `fake`

Прогнати весь флоу локально без жодного банку. `fake` реєструється автоматично в оточеннях `local`/`testing`:

```php
use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Models\Payment;

$payment = Payment::create([
    'status' => 'pending',
    'type' => 'charge',
    'gateway' => 'fake',
    'amount' => 10000, // мінорні одиниці — 100.00
    'currency_code' => 'UAH',
    'payable_type' => Order::class,
    'payable_id' => $order->id,
    'billable_type' => $order->user::class,
    'billable_id' => $order->user->id,
]);

$result = app(BillingManager::class)->charge($payment);

return redirect($result->url); // локальна сторінка з кнопками "Оплачено"/"Відхилено"
```

Натискання кнопки надсилає POST напряму на реальний, зареєстрований webhook-ендпоінт — той самий пайплайн перевірки підпису → `ProcessWebhookJob` → подія, яким пройшов би реальний гейтвей, не скорочений шлях.

## Налаштування реального гейтвея

Опублікований конфіг уже містить заготовки всіх п'яти вбудованих гейтвеїв — лишається заповнити `.env` для тих, якими користуєтесь:

```dotenv
MONOBANK_TOKEN=

LIQPAY_PUBLIC_KEY=
LIQPAY_PRIVATE_KEY=

WAYFORPAY_MERCHANT_ACCOUNT=
WAYFORPAY_MERCHANT_DOMAIN=
WAYFORPAY_SECRET_KEY=

STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=

HUTKO_MERCHANT_ID=
HUTKO_SECRET_KEY=
```

Решту не чіпайте — незаповнений гейтвей просто лишається неналаштованим і впаде лише тоді, коли ним реально спробують провести оплату.

Той самий перелік у рантаймі, якщо будуєте UI налаштувань, а не читаєте файл — у кожного драйвера є статичний `credentialFields()`, викликається прямо на класі, без інстанції/кредів:

```php
use Fomvasss\Billing\Gateways\Monobank\MonobankGateway;

MonobankGateway::credentialFields();
// [
//     ['name' => 'token', 'type' => 'text', 'secret' => true, 'help' => 'X-Token з кабінету мерчанта...'],
//     ['name' => 'link_ttl_minutes', 'type' => 'number', 'secret' => false, 'help' => 'TTL посилання на оплату, хв...'],
// ]
```

`name` — ключ у конфізі (`config('billing.gateways.monobank.token')`), `secret` — позначає чутливе поле (ховати в UI налаштувань як пароль), `help` — звідки взяти значення. Ті самі дані для всіх зареєстрованих гейтвеїв одразу, без імпорту кожного класу драйвера окремо:

```php
use Fomvasss\Billing\Facades\Billing;

Billing::gateways(); // ['monobank' => ['label' => 'Monobank Acquiring', 'currencies' => [...], 'credential_fields' => [...], 'webhook_url' => '...', 'capabilities' => [...]], ...]
Billing::gateway('monobank'); // лише запис цього гейтвея, або null, якщо не зареєстрований
```

`webhook_url` у цьому масиві — точний callback URL для кабінету відповідного гейтвея.

Динамічні креди per-tenant (замість статичного масиву в конфізі) — забіндити власний резолвер:

```php
$this->app->bind(\Fomvasss\Billing\Contracts\CredentialResolverContract::class, MyCredentialResolver::class);
```

## `Payable` і `Billable`

`payable` — за що платять (`Order`, цикл підписки `Subscription`); `billable` — хто платить. Обидва поліморфні — будь-яка Eloquent-модель годиться для `payable`, а `billable`-моделі потрібен метод `tenantId()` (використовується для резолвингу динамічних per-tenant кредів):

```php
use Fomvasss\Billing\Concerns\Billable as BillableConcern;
use Fomvasss\Billing\Contracts\Billable;

class Organization extends Model implements Billable
{
    use BillableConcern; // дефолтний tenantId(): null — перевизначити нижче, якщо потрібна мультитенантність

    public function tenantId(): ?string
    {
        return (string) $this->id;
    }
}
```

## Оплата

```php
$result = app(BillingManager::class)->charge($payment, new ChargeOptions(
    description: 'Order #1042',
    customerEmail: $order->user->email,
    successUrl: route('order.thanks', $order),
));

return redirect($payment->payment_url);
```

`charge()` записує `external_id`/`payment_url`/`payment_url_expires_at` назад у `$payment` — безпечно викликати повторно на тому самому `Payment`, коли посилання спливло (TTL визначає кожен драйвер сам). `payment_url` — завжди плоске, редіректабельне посилання, незалежно від гейтвея: навіть LiqPay, чия платіжна сторінка приймає лише клієнтський POST-форми, отримує таке — форма кешується й віддається через власну сторінку пакета, яка сама її сабмітить.

Якщо потрібен сирий результат драйвера напряму (власна API-відповідь для SPA, наприклад): `$result->url` заповнений для будь-якого гейтвея, крім LiqPay, де замість нього `$result->form` (`['action' => ..., 'fields' => [...]]`) — ці поля треба самим відправити POST-ом на вказаний `action`.

### Ручні/офлайн платежі

Для оплати готівкою чи по реквізитам драйвер не потрібен — просто створити рядок напряму:

```php
Payment::create([
    'status' => 'paid',
    'type' => 'charge',
    'gateway' => null, // або вільний рядок на кшталт 'cash' — не зареєстрований через extend()
    'amount' => 10000,
    'currency_code' => 'UAH',
    'payable_type' => Order::class,
    'payable_id' => $order->id,
    'billable_type' => $order->user::class,
    'billable_id' => $order->user->id,
]);
```

`paid_at` виставляється автоматично в момент, коли `status` стає `paid`.

## Схема послідовності

```mermaid
sequenceDiagram
    actor Customer as Клієнт
    participant App as Ваш застосунок
    participant Billing as BillingManager
    participant Driver as Драйвер гейтвея
    participant Bank as Платіжний гейтвей

    App->>Billing: charge($payment, $options)
    Billing->>Driver: charge($payment, $options)
    Driver->>Bank: створення чекауту
    Bank-->>Driver: URL чекауту / форма
    Driver-->>Billing: PaymentResult
    Billing-->>App: external_id/payment_url записано в $payment
    App-->>Customer: редірект на чекаут

    Customer->>Bank: оплачує
    Bank-->>Customer: редірект на successUrl (лише UX — ніколи не джерело підтвердження)

    Bank->>App: webhook POST (сервер-сервер)
    Note over App: SignatureValidator перевіряє підпис,<br/>WebhookCall збережено, ProcessWebhookJob у черзі
    App->>Driver: handleWebhook($webhookCall)
    Driver-->>App: WebhookResult
    Note over App: Payment.status оновлено,<br/>дедуп-клейм на webhook_calls
    App->>App: подія PaymentSucceeded
    App-->>App: ваш лістенер реагує (фулфілмент замовлення тощо)
```

Два незалежні шляхи, навмисно: редірект браузера (верхня половина) — лише UX, вебхук (нижня половина) — єдине, що коли-небудь змінює `Payment.status` — деталі нижче.

## Вебхуки

Один маршрут (`POST /billing/webhooks/{gateway}`) обслуговує всі гейтвеї — резолвиться в момент запиту через власний реєстр `BillingManager`, нічого налаштовувати вручну. Вхідні вебхуки перевіряються на підпис, зберігаються (`billing_webhook_calls`), ставляться в чергу (`ProcessWebhookJob`) і перетворюються на одну з подій:

| Подія | Коли |
|---|---|
| `PaymentSucceeded` / `PaymentFailed` / `PaymentRefunded` | Статус `Payment` дійшов до термінального стану |
| `SubscriptionCreated` / `SubscriptionRenewed` / `SubscriptionPaymentFailed` / `SubscriptionCancelled` / `TrialWillEnd` | Гейтвей-driven зміна стану підписки (лише нативні підписки гейтвея) |
| `SubscriptionPaused` / `SubscriptionResumed` | Лише локально, через `$subscription->pause()`/`resume()` — гейтвей не бере участі |
| `PaymentMethodAttached` / `PaymentMethodDetached` | Збережена картка/токен прив'язана або відв'язана |
| `UsageLimitReached` | `Subscription::reportUsage()` перетнув `price.included_units` |

Слухати звичайним чином:

```php
Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    $event->payment->payable; // ваш Order, Subscription тощо
});
```

### Кастомізація webhook-маршруту

І шлях, і стек мідлварів — конфігуровані. `{gateway}` має лишитись десь у шляху (`WebhookController` резолвить драйвер саме з цього сегмента), решта — на ваш розсуд:

```php
// config/billing.php
'webhook' => [
    'path' => 'webhook/billing/{gateway}', // власний префікс замість дефолтного billing/webhooks/{gateway}
    'middleware' => ['throttle:60,1'], // порожньо за замовчуванням — webhook-ендпоінт навмисно поза групою `web` (без CSRF, без сесії)
],
```

Назва маршруту (`billing.webhook`) незмінна — `AbstractGateway::webhookUrl()` і поле `webhook_url` в `Billing::gateways()` і далі резолвляться коректно незалежно від сконфігурованого шляху, нічого більше оновлювати не потрібно.

### Власний гейтвей

Чотири обов'язкові методи (`PaymentGatewayContract`), решта — опційно, і один виклик для реєстрації:

```php
// у власному ServiceProvider::boot() — проєкт-споживач або сателіт-пакет (fomvasss/laravel-billing-mygateway)
use Fomvasss\Billing\Facades\Billing;

Billing::extend('mygateway', MyGateway::class)
    ->registerWebhook('mygateway', MyGatewaySignatureValidator::class);
```

Ні маршруту оголошувати, ні конфіг-файлу чіпати — усі гейтвеї діляться одним маршрутом `POST /billing/webhooks/{gateway}`, який резолвиться через цей реєстр у момент запиту.

**→ [Повний гайд: як написати гейтвей](docs/writing-a-gateway.md)** (англійською) — контракт метод за методом, перевірка підпису, три форми токенізації, кастомні відповіді-підтвердження, тестування без реальних кредів і пастки звірки, що коштували нам реальних багів.

## Підписки

```php
$plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);

$price = $plan->prices()->create([
    'gateway' => 'stripe',
    'currency_code' => 'USD',
    'amount' => 2900, // $29.00
    'pricing_type' => 'flat',
    'interval' => 'month',
    'interval_count' => 1,
    'trial_days' => 14,
]);

$subscription = Subscription::create([
    'status' => 'trialing',
    'gateway' => 'stripe',
    'price_id' => $price->id,
    'billable_type' => $organization::class,
    'billable_id' => $organization->id,
    'trial_ends_at' => now()->addDays(14),
]);
```

### Типи прайсингу

- `flat` — фіксована сума, `qty`/`current_usage` ігноруються.
- `licensed` — `amount × subscription.qty` (місця/ліцензії).
- `metered` — `amount × subscription.current_usage` (pay-as-you-go).

### Використання й квоти

`included_units`/`current_usage` — ортогональні до `pricing_type`: навіть `flat`-ціна може мати квоту (наприклад, "4000 AI-токенів у комплекті на місяць, ціна фіксована в будь-якому разі"):

```php
$subscription->reportUsage(quantity: 1500, idempotencyKey: "ai-run:{$run->id}");
$subscription->remainingUsage(); // null, якщо в ціни взагалі нема квоти
```

`UsageLimitReached` спрацьовує рівно раз, коли кумулятивне використання перетинає `included_units` — реакція повністю на боці консьюмера (заблокувати, сповістити, або списати овербюджет через `TokenizesPaymentMethod::chargePaymentMethod()`).

### Пауза / відновлення / скасування

```php
$subscription->pause();   // лише локально — без виклику гейтвея, без події до банку
$subscription->resume();
$subscription->cancel();               // в кінці періоду (за замовчуванням)
$subscription->cancel(atPeriodEnd: false); // негайно
$subscription->swapPlan($newPrice);
```

### Рекурентні списання, реконсиляція, спливання тріалу

Три artisan-команди, вимкнені за замовчуванням (`billing.schedule.enabled`, бо стосуються грошей і стану підписок):

```php
// config/billing.php
'schedule' => ['enabled' => true],
```

| Команда | Запускається | Що робить |
|---|---|---|
| `billing:process-recurring-charges` | щогодини | Знаходить підписки з `current_period_ends_at <= now()` і списує зі збереженого `PaymentMethod` через `chargePaymentMethod()`. Лише ІНІЦІЮЄ списання — результат приходить пізніше через звичайний webhook pipeline, обробляється автоматично (посування періоду при `PaymentSucceeded`, або dunning-цикл через `grace_ends_at`/`recurring_attempts`/`max_recurring_attempts` при `PaymentFailed`, аж до `SubscriptionCancelled`). |
| `billing:reconcile-pending-payments` | кожні 15 хв | Fallback для `Payment`, що завис `pending` через загублений вебхук, або статус `expired` гейтвея, для якого власного вебхука не буває. Бере лише платежі старші за `config('billing.reconcile_after_minutes')` (дефолт 60 хв) — цей cutoff уже сам по собі відкладає, коли платіж кваліфікується як "завис", тому ця команда запускається частіше за інші дві, не щогодини. |
| `billing:expire-trials` | щодня | `trialing`-підписки з простроченим `trial_ends_at` → `ended`. Нічого іншого не чіпає — конвертація trial у платну підписку — звичайний виклик `chargeWithMethod()`, той самий, що й будь-яке продовження (див. "Безкоштовний період" у Практичних прикладах). |

Ніщо з цього не запускається саме собою — `Schedule::command()`/`->hourly()` тощо лише реєструються у власному Laravel-планувальнику застосунку, якому все одно потрібен стандартний системний cron-запис `php artisan schedule:run` щохвилини (звичайна вимога деплою Laravel, не специфіка пакета).

### Токенізація / збережені картки

Усі 5 вбудованих гейтвеїв реалізують `TokenizesPaymentMethod` — прив'язуєте картку один раз, далі `chargeWithMethod()` будь-коли (продовження підписки, овербюджет, апгрейд, ...).

**Stripe** потребує окремого кроку прив'язки, керованого фронтендом:

```php
$customerId = Billing::driver('stripe')->createCustomer($user);

// фронтенд збирає картку через Stripe.js/Elements проти цього customer id, підтверджує
// SetupIntent, отримує PaymentMethod id (pm_...) — POST на свій ендпоінт
$method = Billing::driver('stripe')->attachPaymentMethod($user, ['payment_method_id' => $pmId]);

Billing::chargeWithMethod($payment, $method);
```

**Monobank, LiqPay, WayForPay, Hutko** прив'язуються самі — без окремого кроку, `PaymentMethod` просто з'являється, щойно клієнт оплатив:

```php
// Monobank/LiqPay потребують прапорця, щоб зберегти картку; WayForPay/Hutko зберігають і без нього
Billing::charge($payment, new ChargeOptions(saveCard: true));
// ... клієнт платить, PaymentMethod прив'язується сам, диспатчиться PaymentMethodAttached — більше нічого викликати не треба
```

Уже маєте токен звідкись іншим шляхом? `attachPaymentMethod($billable, [...])` бере його напряму — ключ масиву різний для кожного гейтвея: `payment_method_id` (Stripe), `card_token` (Monobank/LiqPay), `rec_token` (WayForPay), `rectoken` (Hutko). `detachPaymentMethod($method)` видаляє збережену картку — лише Monobank реально відкликає її й на боці банку, решта три просто перестають нею користуватись локально.

У будь-якому разі `chargeWithMethod()`/`chargePaymentMethod()` лише ІНІЦІЮЮТЬ списання — результат завжди приходить через звичайний webhook pipeline, так само як `charge()`.

## Практичні приклади

Усе вище — будівельні блоки; ось як вони складаються в кілька реальних сценаріїв.

### 1. Оплата замовлення в магазині з фіскальним чеком

`Order` реалізує `HasReceiptItems` — `charge()` підхоплює це автоматично (не треба самим передавати `receiptItems`), і Monobank/LiqPay/WayForPay використовують це для фіскалізації кошика на своєму боці:

```php
class Order extends Model implements Payable, HasReceiptItems
{
    public function receiptItems(): array
    {
        return $this->items->map(fn (OrderItem $item) => [
            'name' => $item->product->name,
            'qty' => $item->qty,
            'unitAmount' => $item->unit_price, // мінорні одиниці
            'sku' => $item->product->sku,
        ])->all();
    }
}
```

```php
$payment = Payment::create([
    'status' => 'pending',
    'type' => 'charge',
    'gateway' => 'monobank',
    'amount' => $order->total, // мінорні одиниці
    'currency_code' => 'UAH',
    'payable_type' => Order::class,
    'payable_id' => $order->id,
    'billable_type' => $order->user::class,
    'billable_id' => $order->user->id,
]);

Billing::charge($payment, new ChargeOptions(
    description: "Замовлення #{$order->number}",
    customerEmail: $order->user->email,
));

return redirect($payment->payment_url);
```

```php
Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    if ($event->payment->payable instanceof Order) {
        $event->payment->payable->markAsPaid();
    }
});
```

### 2. Оформлення підписки на 15 ГБ — і як насправді працює автопродовження

```php
$plan = Plan::create(['code' => 'storage-15gb', 'name' => '15 ГБ сховища']);

$price = $plan->prices()->create([
    'gateway' => 'stripe',
    'currency_code' => 'USD',
    'amount' => 500, // $5.00/міс
    'pricing_type' => 'flat',
    'interval' => 'month',
    'interval_count' => 1,
]);

$subscription = Subscription::create([
    'status' => 'active',
    'gateway' => 'stripe',
    'price_id' => $price->id,
    'billable_type' => $organization::class,
    'billable_id' => $organization->id,
    'current_period_ends_at' => now()->addMonth(),
]);
```

Перша оплата токенізує картку (`saveCard: true`, див. "Токенізація" вище). Саме автопродовження — це `billing:process-recurring-charges`, вимкнена за замовчуванням, тож треба увімкнути розклад (`config('billing.schedule.enabled', true)`, що саме вона робить і коли запускається — таблиця вище); усе решта (посування періоду, dunning при невдачі) уже підключено, більше нічого писати не треба.

Крок 3 самим писати не треба — уже підключено. Потрібні лише крок 1 і збережений `PaymentMethod`.

### 3. Докупка 5 ГБ окремо (поза циклом підписки)

Не рядок підписки — у пакеті свідомо немає концепції "гаманця"/балансу на аддони (див. нижче), тому це звичайний одноразовий `Payment`. Сам `Payment` каже лише хто заплатив і скільки, не за що — два різні аддони можуть коштувати однаково. Два способи це вирішити, оберіть залежно від того, скільки різних типів одноразових покупок буде на того самого клієнта:

**`Payment::$meta`** — звичайна `json`-колонка, пакету байдужа (та сама ідея, що `Plan::$meta`), найпростіший варіант, якщо тип одноразової покупки лише один:

```php
$payment = Payment::create([
    'status' => 'pending',
    'type' => 'charge',
    'gateway' => 'stripe',
    'amount' => 200, // $2.00 за 5 ГБ
    'currency_code' => 'USD',
    'payable_type' => $organization::class,
    'payable_id' => $organization->id,
    'billable_type' => $organization::class,
    'billable_id' => $organization->id,
    'meta' => ['product' => 'storage_addon', 'gb' => 5],
]);

Billing::chargeWithMethod($payment, $organization->defaultPaymentMethod); // або Billing::charge() для редіректного чекауту
```

```php
Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    if (($event->payment->meta['product'] ?? null) === 'storage_addon') {
        $event->payment->payable->increment('extra_storage_gb', $event->payment->meta['gb']);
    }
});
```

**Окремий `payable`** — має сенс, коли типів одноразових покупок на того самого клієнта кілька (аддони на сховище, докупка місць, ...) і хочеться `instanceof` замість рядкових ключів у `meta`. Та сама ідея, що `Order` у рецепті №1:

```php
class StorageAddonPurchase extends Model implements Payable
{
    protected $fillable = ['organization_id', 'gb'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

```php
$addon = StorageAddonPurchase::create(['organization_id' => $organization->id, 'gb' => 5]);

Payment::create([
    // ... ті самі поля, що вище, окрім:
    'payable_type' => StorageAddonPurchase::class,
    'payable_id' => $addon->id,
]);
```

```php
Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    if ($event->payment->payable instanceof StorageAddonPurchase) {
        $event->payment->payable->organization->increment('extra_storage_gb', $event->payment->payable->gb);
    }
});
```

У будь-якому разі: продаватимете пізніше ще й 10 ГБ чи 20 ГБ за іншою ціною — той самий лістенер, без нової гілки: кількість лежить на `meta`/моделі `payable`, а не вгадується із суми платежу.

### 4. Безкоштовний період (trial)

Без жодного виклику гейтвея, без `PaymentMethod` — просто рядок `Subscription`:

```php
$subscription = Subscription::create([
    'status' => 'trialing',
    'gateway' => 'stripe',
    'price_id' => $price->id,
    'billable_type' => $organization::class,
    'billable_id' => $organization->id,
    'trial_ends_at' => now()->addDays(14),
]);
```

`TrialWillEnd` спрацьовує, щоб запросити картку до кінця trial. Якщо ніхто не конвертувався — `billing:expire-trials` (щодня) знаходить `trialing`-підписки з простроченим `trial_ends_at` і переводить у `ended`. Конвертація в середині trial чи прямо в кінці — той самий виклик: `chargeWithMethod()` на `Payment` цієї підписки одразу переводить її в `active` при `PaymentSucceeded` (лістенеру байдуже, що вона починалась як `trialing`) — окремого методу "конвертувати trial" викликати не треба.

### 5. Кілька незалежних підписок на одного клієнта одночасно

`Subscription::$billable_id` не унікальний — одна `Organization` може мати скільки завгодно одночасних, незалежно оплачуваних підписок (базовий план, AI-аддон, аддон на канал, ...), кожна зі своїм гейтвеєм/статусом/циклом продовження:

```php
foreach (['base' => 'stripe', 'ai-addon' => 'stripe', 'channel-viber' => 'wayforpay'] as $planCode => $gateway) {
    Subscription::create([
        'status' => 'active',
        'gateway' => $gateway,
        'price_id' => Plan::where('code', $planCode)->firstOrFail()->prices()->firstOrFail()->id,
        'billable_type' => $organization::class,
        'billable_id' => $organization->id,
        'current_period_ends_at' => now()->addMonth(),
    ]);
}
```

Скасування чи спливання однієї не зачіпає решту — кожен рядок має власний незалежний життєвий цикл.

## Хелпери моделей

Те, що інакше довелось би писати самим у кожному проєкті:

```php
// Subscription
$subscription->isActive();     // має право користуватись сервісом прямо зараз — trialing, active,
                               // або past_due, але ще в межах grace-вікна dunning'у
$subscription->onTrial();      // trialing і trial_ends_at ще не минув
$subscription->onGracePeriod();// продовження не вдалось, але ретраї ще тривають
$subscription->isCanceled();
$subscription->isCancelling(); // викликали cancel() на кінець періоду — до того ще працює

Subscription::active()->get();               // trialing + active
Subscription::forBillable($organization)->get();
```

```php
// Payment
$payment->isPaid();
$payment->isPending();
$payment->isFailed();
$payment->isRefund();               // цей рядок — рефанд (type=refund), не списання
$payment->refundedAmount();         // сумарно повернуто за цим списанням, мінорні одиниці
$payment->hasActivePaymentUrl();    // посилання на оплату ще живе — не треба знову charge()

Payment::paid()->get();
Payment::pending()->get();
Payment::forBillable($organization)->latest()->get();
```

`isActive()` — саме те, що варто використовувати в gate/middleware: він навмисно лишає доступ увімкненим на час grace-вікна, щоб клієнта не відрізало посеред ретраїв через картку, яка один раз не пройшла.

## Конвертація валют

Якщо валюта `Price` не приймається обраним гейтвеєм, `BillingManager::resolveChargeAmount()` пробує по черзі: (1) власну валюту ціни, якщо приймається; (2) сіблінг-`Price` того ж `Plan`+гейтвея в прийнятній валюті; (3) забінджений `CurrencyConverterContract`; (4) кидає `BillingException`. Забіндити конвертер (напр. адаптер над [`fomvasss/laravel-currency`](https://github.com/fomvasss/laravel-currency), не жорстка залежність цього пакета):

```php
$this->app->bind(\Fomvasss\Billing\Contracts\CurrencyConverterContract::class, MyCurrencyConverter::class);
```

## Тестування

Використовуйте гейтвей `fake` (див. "Швидкий старт") у feature-тестах власного застосунку — він проганяє точнісінько той самий пайплайн, що й реальний гейтвей, тому нічого специфічного для пакета мокати не потрібно.

## Ліцензія

MIT
