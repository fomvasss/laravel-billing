# Testing

## Feature tests with the `fake` gateway

In `local` and `testing` the `fake` gateway is registered automatically. It runs the real pipeline — the webhook route, storage, `ProcessWebhookJob`, dedup, events — so there is nothing package-specific to mock:

```php
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Illuminate\Support\Facades\Event;

public function test_an_order_is_fulfilled_when_paid(): void
{
    Event::fake([PaymentSucceeded::class]); // or keep real listeners and assert their effect

    $payment = Payment::create([
        'gateway' => 'fake',
        'amount' => 10000,
        'currency' => 'UAH',
        'payable_type' => $order->getMorphClass(),
        'payable_id' => $order->id,
        'billable_type' => $user->getMorphClass(),
        'billable_id' => $user->id,
    ]);

    Billing::charge($payment);

    $this->postJson(route('billing.webhook', ['gateway' => 'fake']), [
        'payment_id' => $payment->id,
        'result' => 'success', // anything else = failure
    ])->assertOk();

    $this->assertTrue($payment->fresh()->isPaid());
    Event::assertDispatched(PaymentSucceeded::class);
}
```

With `QUEUE_CONNECTION=sync` (the usual testing default) the job runs inline.

The fake driver doesn't tokenize, refund or poll status. For those paths, test against a real driver with `Http::fake()`.

## Real drivers with `Http::fake()`

Drivers use Laravel's HTTP client, so gateway calls can be faked:

```php
Http::fake([
    'api.monobank.ua/api/merchant/invoice/create' => Http::response(['invoiceId' => 'inv_1', 'pageUrl' => 'https://pay.mbnk.biz/inv_1']),
]);

config(['billing.gateways.monobank.token' => 'test', 'billing.return_urls.success' => 'https://app.test/ok']);

Billing::charge($payment);

$this->assertSame('https://pay.mbnk.biz/inv_1', $payment->payment_url);
Http::assertSent(fn ($request) => $request['merchantPaymInfo']['reference'] === $payment->id);
```

For incoming webhooks of a real gateway, compute the signature with the test secret in the test itself — the formulas per gateway are in [Testing webhooks by hand](../guides/webhook-testing.md).

## Scheduled commands

The commands can be called directly:

```php
$this->travelTo($subscription->current_period_ends_at->addMinute());
$this->artisan('billing:process-recurring-charges')->assertSuccessful();
```

## Manual testing

Clicking through the fake checkout page, replaying gateway callbacks from Postman/curl, and receiving real webhooks locally through a tunnel — [Testing webhooks by hand](../guides/webhook-testing.md).
