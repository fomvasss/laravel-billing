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
 * Like WayForPay, no opt-in flag — rectoken is part of the same shared response schema every
 * approved payment already returns. See "Токенізація" in the package plan.
 */
class HutkoTokenizationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.hutko.merchant_id', '1');
        $app['config']->set('billing.gateways.hutko.secret_key', 'secret_test');
    }

    public function test_an_approved_webhook_with_a_rectoken_attaches_it_exactly_once(): void
    {
        Event::fake([PaymentMethodAttached::class]);

        $user = $this->makeUser();
        $payment = $this->pendingPayment($user);

        $payload = [
            'order_id' => (string) $payment->id,
            'order_status' => 'approved',
            'rectoken' => 'rec_tok_1',
            'masked_card' => '444444XXXXXX5555',
            'card_type' => 'VISA',
        ];

        $webhookCall = new BillingWebhookCall(['name' => 'hutko', 'payload' => $payload]);

        Billing::driver('hutko')->handleWebhook($webhookCall);

        $method = PaymentMethod::query()->where('gateway', 'hutko')->where('external_id', 'rec_tok_1')->firstOrFail();
        $this->assertSame('5555', $method->last4);
        $this->assertSame('VISA', $method->brand);
        $this->assertTrue($method->is_default);
        $this->assertSame('paid', $payment->fresh()->status->value);

        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);
    }

    public function test_attaching_a_known_token_persists_it_without_any_http_call(): void
    {
        Event::fake([PaymentMethodAttached::class]);
        Http::fake();

        $user = $this->makeUser();

        $method = Billing::driver('hutko')->attachPaymentMethod($user, ['rectoken' => 'rec_tok_2']);

        $this->assertSame('rec_tok_2', $method->external_id);
        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);
        Http::assertNothingSent();
    }

    public function test_charging_a_payment_method_calls_api_recurring_with_the_stored_rectoken(): void
    {
        Http::fake([
            'https://pay.hutko.org/api/recurring' => Http::response(['response' => ['order_status' => 'approved', 'payment_id' => 777]]),
        ]);

        $user = $this->makeUser();
        $payment = $this->pendingPayment($user);
        $method = PaymentMethod::create([
            'gateway' => 'hutko',
            'external_customer_id' => 'cust_1',
            'external_id' => 'rec_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $result = Billing::driver('hutko')->chargePaymentMethod($payment, $method);

        $this->assertSame('777', $result->externalId);

        Http::assertSent(fn ($request) => $request->url() === 'https://pay.hutko.org/api/recurring'
            && $request['request']['rectoken'] === 'rec_tok_1'
            && $request['request']['order_id'] === (string) $payment->id
            && isset($request['request']['client_ip']));
    }

    public function test_detaching_a_payment_method_deletes_it_locally_without_any_http_call(): void
    {
        Event::fake([PaymentMethodDetached::class]);
        Http::fake();

        $user = $this->makeUser();
        $method = PaymentMethod::create([
            'gateway' => 'hutko',
            'external_customer_id' => 'cust_1',
            'external_id' => 'rec_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::driver('hutko')->detachPaymentMethod($method);

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
            'gateway' => 'hutko',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
