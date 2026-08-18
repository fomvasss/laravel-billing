<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Events\PaymentMethodAttached;
use Fomvasss\Billing\Events\PaymentMethodDetached;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * WayForPay has no opt-in flag for tokenization — recToken comes back automatically on any
 * approved card payment, in the same callback as the payment status. See "Токенізація" in the
 * package plan.
 */
class WayForPayTokenizationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'test_merchant');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'secret_test');
    }

    public function test_an_approved_webhook_with_a_rectoken_attaches_it_exactly_once(): void
    {
        Event::fake([PaymentMethodAttached::class]);

        $user = $this->makeUser();
        $payment = $this->pendingPayment($user);

        $payload = [
            'orderReference' => (string) $payment->id,
            'transactionStatus' => 'Approved',
            'recToken' => 'rec_tok_1',
            'cardPan' => '41****8217',
            'cardType' => 'visa',
        ];

        $webhookCall = new BillingWebhookCall(['name' => 'wayforpay', 'payload' => $payload]);

        Billing::driver('wayforpay')->handleWebhook($webhookCall);

        $method = PaymentMethod::query()->where('gateway', 'wayforpay')->where('external_id', 'rec_tok_1')->firstOrFail();
        $this->assertSame('8217', $method->last4);
        $this->assertSame('visa', $method->brand);
        $this->assertTrue($method->is_default);
        $this->assertSame('paid', $payment->fresh()->status->value);

        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);
    }

    public function test_a_redelivered_approved_callback_does_not_fire_attached_again(): void
    {
        Event::fake([PaymentMethodAttached::class]);

        $user = $this->makeUser();
        $payment = $this->pendingPayment($user);

        $payload = [
            'orderReference' => (string) $payment->id,
            'transactionStatus' => 'Approved',
            'recToken' => 'rec_tok_dup',
        ];

        // attachFromWebhook dispatches directly, BEFORE the job-level dedup claim — the
        // wasRecentlyCreated guard is what keeps a WayForPay re-delivery from double-firing.
        Billing::driver('wayforpay')->handleWebhook(new BillingWebhookCall(['name' => 'wayforpay', 'payload' => $payload]));
        Billing::driver('wayforpay')->handleWebhook(new BillingWebhookCall(['name' => 'wayforpay', 'payload' => $payload]));

        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);
    }

    public function test_attaching_a_known_token_persists_it_without_any_http_call(): void
    {
        Event::fake([PaymentMethodAttached::class]);
        Http::fake();

        $user = $this->makeUser();

        $method = Billing::driver('wayforpay')->attachPaymentMethod($user, ['rec_token' => 'rec_tok_2']);

        $this->assertSame('rec_tok_2', $method->external_id);
        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);
        Http::assertNothingSent();
    }

    public function test_charging_a_payment_method_sends_a_charge_request_with_the_stored_rectoken(): void
    {
        Http::fake([
            'https://api.wayforpay.com/api' => Http::response(['orderReference' => 'x', 'transactionStatus' => 'Approved']),
        ]);

        $user = $this->makeUser();
        $payment = $this->pendingPayment($user);
        $method = PaymentMethod::create([
            'gateway' => 'wayforpay',
            'external_customer_id' => 'cust_1',
            'external_id' => 'rec_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $result = Billing::driver('wayforpay')->chargePaymentMethod($payment, $method);

        $this->assertNotNull($result->raw);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.wayforpay.com/api'
            && $request['transactionType'] === 'CHARGE'
            && $request['recToken'] === 'rec_tok_1'
            && $request['merchantTransactionSecureType'] === 'NON3DS'
            && $request['orderReference'] === (string) $payment->id);
    }

    public function test_charging_a_payment_method_with_receipt_items_sends_them_as_products(): void
    {
        Http::fake([
            'https://api.wayforpay.com/api' => Http::response(['orderReference' => 'x', 'transactionStatus' => 'Approved']),
        ]);

        $user = $this->makeUser();
        $payment = $this->pendingPayment($user);
        $method = PaymentMethod::create([
            'gateway' => 'wayforpay',
            'external_customer_id' => 'cust_1',
            'external_id' => 'rec_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::driver('wayforpay')->chargePaymentMethod($payment, $method, new \Fomvasss\Billing\DTO\ChargeOptions(
            receiptItems: [['name' => 'Widget', 'qty' => 1, 'unitAmount' => 10000, 'sku' => 'WID-1']],
        ));

        Http::assertSent(fn ($request) => $request['productName'] === ['Widget'] && $request['productCount'] === [1]);
    }

    public function test_detaching_a_payment_method_deletes_it_locally_without_any_http_call(): void
    {
        Event::fake([PaymentMethodDetached::class]);
        Http::fake();

        $user = $this->makeUser();
        $method = PaymentMethod::create([
            'gateway' => 'wayforpay',
            'external_customer_id' => 'cust_1',
            'external_id' => 'rec_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::driver('wayforpay')->detachPaymentMethod($method);

        $this->assertDatabaseMissing('billing_payment_methods', ['id' => $method->id]);
        Event::assertDispatched(PaymentMethodDetached::class);
        Http::assertNothingSent();
    }

    private function makeUser(): TestUser
    {
        return TestUser::create(['name' => 'Buyer']);
    }

    private function pendingPayment(TestUser $user): Payment
    {
        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'wayforpay',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
