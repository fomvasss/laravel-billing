<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\DTO\ChargeOptions;
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
 * Unlike Monobank, LiqPay's card_token arrives in the SAME server_url callback as the payment
 * status — one delivery, not two. See "Токенізація" in the package plan.
 */
class LiqPayTokenizationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.liqpay.public_key', 'pub_test');
        $app['config']->set('billing.gateways.liqpay.private_key', 'priv_test');
    }

    public function test_charge_with_save_card_requests_a_token(): void
    {
        $payment = $this->pendingLiqPayPayment($this->makeUser());

        $result = Billing::driver('liqpay')->charge($payment, new ChargeOptions(saveCard: true));

        $decoded = json_decode(base64_decode($result->form['fields']['data']), true);
        $this->assertSame('1', $decoded['recurringbytoken']);
    }

    public function test_a_successful_webhook_with_a_card_token_attaches_it_exactly_once(): void
    {
        Event::fake([PaymentMethodAttached::class]);

        $user = $this->makeUser();
        $payment = $this->pendingLiqPayPayment($user);

        $decoded = [
            'status' => 'success',
            'order_id' => (string) $payment->id,
            'payment_id' => 555,
            'card_token' => 'card_tok_1',
            'sender_card_mask2' => '424242xxxxxx4242',
            'sender_card_type' => 'visa',
        ];

        $webhookCall = new BillingWebhookCall(['name' => 'liqpay', 'payload' => ['data' => base64_encode(json_encode($decoded))]]);

        Billing::driver('liqpay')->handleWebhook($webhookCall);

        $method = PaymentMethod::query()->where('gateway', 'liqpay')->where('external_id', 'card_tok_1')->firstOrFail();
        $this->assertSame('4242', $method->last4);
        $this->assertSame('visa', $method->brand);
        $this->assertTrue($method->is_default);
        $this->assertSame('paid', $payment->fresh()->status->value);

        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);
    }

    public function test_attaching_a_known_token_persists_it_without_any_http_call(): void
    {
        Event::fake([PaymentMethodAttached::class]);
        Http::fake(); // fails the test if any request is attempted

        $user = $this->makeUser();

        $method = Billing::driver('liqpay')->attachPaymentMethod($user, ['card_token' => 'card_tok_2']);

        $this->assertSame('card_tok_2', $method->external_id);
        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);
        Http::assertNothingSent();
    }

    public function test_charging_a_payment_method_calls_paytoken_with_the_stored_token(): void
    {
        Http::fake([
            'https://www.liqpay.ua/api/request' => Http::response(['status' => 'success', 'payment_id' => 777]),
        ]);

        $user = $this->makeUser();
        $payment = $this->pendingLiqPayPayment($user);
        $method = PaymentMethod::create([
            'gateway' => 'liqpay',
            'external_customer_id' => 'cust_1',
            'external_id' => 'card_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $result = Billing::driver('liqpay')->chargePaymentMethod($payment, $method);

        $this->assertSame('777', $result->externalId);

        Http::assertSent(function ($request) use ($payment) {
            $decoded = json_decode(base64_decode($request['data']), true);

            return $decoded['action'] === 'paytoken'
                && $decoded['card_token'] === 'card_tok_1'
                && $decoded['order_id'] === (string) $payment->id
                && $decoded['is_recurring'] === true
                && isset($decoded['ip']);
        });
    }

    public function test_detaching_a_payment_method_deletes_it_locally_without_any_http_call(): void
    {
        Event::fake([PaymentMethodDetached::class]);
        Http::fake();

        $user = $this->makeUser();
        $method = PaymentMethod::create([
            'gateway' => 'liqpay',
            'external_customer_id' => 'cust_1',
            'external_id' => 'card_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::driver('liqpay')->detachPaymentMethod($method);

        $this->assertDatabaseMissing('billing_payment_methods', ['id' => $method->id]);
        Event::assertDispatched(PaymentMethodDetached::class);
        Http::assertNothingSent();
    }

    private function makeUser(): TestUser
    {
        return TestUser::create(['name' => 'Buyer']);
    }

    private function pendingLiqPayPayment(TestUser $user): Payment
    {
        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'liqpay',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
