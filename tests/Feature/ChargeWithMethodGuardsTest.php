<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/** A caller-side mixup must never reach the gateway and debit the wrong card. */
class ChargeWithMethodGuardsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_a_method_from_another_gateway_is_rejected_before_any_http_call(): void
    {
        Http::fake();

        $user = TestUser::create(['name' => 'Buyer']);
        $payment = $this->pendingStripePayment($user);
        $method = $this->method('monobank', $user);

        try {
            Billing::chargeWithMethod($payment, $method);
            $this->fail('Expected BillingException.');
        } catch (BillingException) {
        }

        Http::assertNothingSent();
    }

    public function test_a_method_belonging_to_another_billable_is_rejected(): void
    {
        Http::fake();

        $payment = $this->pendingStripePayment(TestUser::create(['name' => 'Buyer']));
        $method = $this->method('stripe', TestUser::create(['name' => 'Someone else']));

        try {
            Billing::chargeWithMethod($payment, $method);
            $this->fail('Expected BillingException.');
        } catch (BillingException) {
        }

        Http::assertNothingSent();
    }

    private function pendingStripePayment(TestUser $user): Payment
    {
        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'stripe',
            'amount' => 10000,
            'currency_code' => 'USD',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }

    private function method(string $gateway, TestUser $user): PaymentMethod
    {
        return PaymentMethod::create([
            'gateway' => $gateway,
            'external_customer_id' => 'cus_' . uniqid(),
            'external_id' => 'pm_' . uniqid(),
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
