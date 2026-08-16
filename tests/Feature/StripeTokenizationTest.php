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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Stripe is the one built-in gateway where TokenizesPaymentMethod fits the contract shape as-is
 * (a synchronous frontend-SDK token, not an async one arriving via webhook like Monobank/LiqPay/
 * WayForPay would need) — see "Токенізація" in the package plan.
 */
class StripeTokenizationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_attaching_a_payment_method_creates_a_stripe_customer_and_persists_the_card(): void
    {
        Event::fake([PaymentMethodAttached::class]);
        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_123']),
            'https://api.stripe.com/v1/customers/cus_123' => Http::response(['id' => 'cus_123']),
            'https://api.stripe.com/v1/payment_methods/pm_123/attach' => Http::response([
                'id' => 'pm_123',
                'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
            ]),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);

        $method = Billing::driver('stripe')->attachPaymentMethod($user, ['payment_method_id' => 'pm_123']);

        $this->assertSame('cus_123', $method->external_customer_id);
        $this->assertSame('pm_123', $method->external_id);
        $this->assertSame('visa', $method->brand);
        $this->assertSame('4242', $method->last4);
        $this->assertTrue($method->is_default);
        $this->assertSame(TestUser::class, $method->billable_type);
        $this->assertSame((string) $user->id, (string) $method->billable_id);
        $this->assertTrue($method->expires_at->isSameDay('2030-12-31'));

        Event::assertDispatched(PaymentMethodAttached::class, fn ($event) => $event->paymentMethod->is($method));

        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/payment_methods/pm_123/attach'
            && $request['customer'] === 'cus_123');
    }

    public function test_a_second_attach_demotes_the_previous_default_method(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_123']),
            'https://api.stripe.com/v1/customers/cus_123' => Http::response(['id' => 'cus_123']),
            'https://api.stripe.com/v1/payment_methods/pm_new/attach' => Http::response([
                'id' => 'pm_new',
                'card' => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 1, 'exp_year' => 2031],
            ]),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);

        $old = PaymentMethod::create([
            'gateway' => 'stripe',
            'external_customer_id' => 'cus_123',
            'external_id' => 'pm_old',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::driver('stripe')->attachPaymentMethod($user, ['payment_method_id' => 'pm_new']);

        $this->assertFalse($old->fresh()->is_default);
    }

    public function test_charging_a_payment_method_creates_an_off_session_payment_intent(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_123', 'status' => 'succeeded']),
        ]);

        [$payment, $method] = $this->paymentAndMethod();

        $result = Billing::driver('stripe')->chargePaymentMethod($payment, $method);

        $this->assertSame('pi_123', $result->externalId);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/payment_intents'
            && $request['amount'] === 2900
            && $request['currency'] === 'usd'
            && $request['customer'] === 'cus_123'
            && $request['payment_method'] === 'pm_123'
            && $request['off_session'] === true
            && $request['confirm'] === true);
    }

    public function test_a_declined_card_does_not_throw_and_still_returns_the_payment_intent_id(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response([
                'error' => [
                    'type' => 'card_error',
                    'code' => 'card_declined',
                    'payment_intent' => ['id' => 'pi_declined'],
                ],
            ], 402),
        ]);

        [$payment, $method] = $this->paymentAndMethod();

        $result = Billing::driver('stripe')->chargePaymentMethod($payment, $method);

        $this->assertSame('pi_declined', $result->externalId);
    }

    public function test_a_non_card_error_response_throws(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response([
                'error' => ['type' => 'invalid_request_error', 'code' => 'parameter_missing'],
            ], 400),
        ]);

        [$payment, $method] = $this->paymentAndMethod();

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        Billing::driver('stripe')->chargePaymentMethod($payment, $method);
    }

    public function test_a_successful_payment_intent_webhook_marks_the_payment_paid(): void
    {
        [$payment, $method] = $this->paymentAndMethod();
        $payment->update(['external_id' => 'pi_123']);

        Billing::driver('stripe')->handleWebhook(new \Fomvasss\Billing\Webhooks\BillingWebhookCall([
            'name' => 'stripe',
            'payload' => [
                'type' => 'payment_intent.succeeded',
                'data' => ['object' => ['id' => 'pi_123', 'metadata' => ['payment_id' => (string) $payment->id]]],
            ],
        ]));

        $this->assertSame('paid', $payment->fresh()->status->value);
    }

    public function test_detaching_a_payment_method_calls_stripe_and_deletes_the_row(): void
    {
        Event::fake([PaymentMethodDetached::class]);
        Http::fake([
            'https://api.stripe.com/v1/payment_methods/pm_123/detach' => Http::response(['id' => 'pm_123']),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $method = PaymentMethod::create([
            'gateway' => 'stripe',
            'external_customer_id' => 'cus_123',
            'external_id' => 'pm_123',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::driver('stripe')->detachPaymentMethod($method);

        $this->assertDatabaseMissing('billing_payment_methods', ['id' => $method->id]);
        Event::assertDispatched(PaymentMethodDetached::class);
    }

    /** @return array{0: Payment, 1: PaymentMethod} */
    private function paymentAndMethod(): array
    {
        $user = TestUser::create(['name' => 'Buyer']);

        $payment = Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'stripe',
            'amount' => 2900,
            'currency' => 'USD',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $method = PaymentMethod::create([
            'gateway' => 'stripe',
            'external_customer_id' => 'cus_123',
            'external_id' => 'pm_123',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        return [$payment, $method];
    }
}
