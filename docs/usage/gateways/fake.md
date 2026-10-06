# Fake gateway

Gateway name `fake`, driver `Gateways\Fake\FakeGateway`. Registered automatically in the `local` and `testing` environments only — `Billing::extend('fake', ...)` throws anywhere else.

```php
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

return redirect($payment->payment_url); // billing/fake/{payment}
```

The page (`billing.fake.show`) shows the amount and two buttons, **Paid** and **Rejected**. Each posts straight to `POST /billing/webhooks/fake` with `payment_id` and `result` (`success` or anything else = failure) — the same storage → queued job → dedup → events pipeline a real gateway goes through.

- No signature check, no amount check, no return URLs needed.
- After a click the browser stays on the webhook's JSON response (`{"message":"ok"}`) — the fake page doesn't redirect to your return pages.
- No checkout expiry, no refunds, no saved cards, no status polling. `billing:reconcile-pending-payments` writes a fake payment left pending past `reconcile_after_minutes` off as `canceled`.
- `billing:health fake` is always up.

Supported currencies: UAH, USD, EUR.
