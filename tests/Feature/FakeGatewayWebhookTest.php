<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestOrder;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * The same cycle verified by hand in sandbox before this suite existed (see "Кроки реалізації"
 * п.3+4 in the package plan): charge() → real HTTP POST to the fake gateway's webhook route →
 * spatie stores the WebhookCall → ProcessWebhookJob (sync queue) → Payment updated → event fired.
 */
class FakeGatewayWebhookTest extends TestCase
{
    public function test_charge_returns_a_url_to_the_fake_payment_page(): void
    {
        $payment = $this->createPendingPayment();

        $result = app(BillingManager::class)->charge($payment);

        $this->assertStringContainsString('/billing/fake/' . $payment->id, $result->url);
        $this->assertSame($result->url, $payment->fresh()->payment_url);
    }

    public function test_a_successful_webhook_marks_the_payment_paid_and_fires_the_event(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $payment = $this->createPendingPayment();

        $this->postJson(route('webhook-client-fake'), [
            'payment_id' => $payment->id,
            'result' => 'success',
        ])->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        Event::assertDispatched(PaymentSucceeded::class, fn ($event) => $event->payment->is($payment));
    }

    public function test_a_rejected_webhook_marks_the_payment_failed_without_touching_paid_at(): void
    {
        $payment = $this->createPendingPayment();

        $this->postJson(route('webhook-client-fake'), [
            'payment_id' => $payment->id,
            'result' => 'failure',
        ])->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertNull($payment->paid_at);
    }

    public function test_a_duplicate_webhook_delivery_does_not_fire_the_event_twice(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $payment = $this->createPendingPayment();
        $body = ['payment_id' => $payment->id, 'result' => 'success'];

        $this->postJson(route('webhook-client-fake'), $body)->assertOk();
        $this->postJson(route('webhook-client-fake'), $body)->assertOk();

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    private function createPendingPayment(): Payment
    {
        $order = TestOrder::create(['title' => 'Order #1']);
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => PaymentStatus::Pending,
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 5000,
            'currency_code' => 'UAH',
            'payable_type' => TestOrder::class,
            'payable_id' => $order->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
