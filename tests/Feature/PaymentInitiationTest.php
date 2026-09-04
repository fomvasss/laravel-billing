<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Enums\PaymentInitiation;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * "Why did money leave my account?" is a question about who started the charge, and the answer has
 * to survive in the row — a consumer cannot reconstruct it later from the payment alone.
 */
class PaymentInitiationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_a_checkout_is_recorded_as_manual(): void
    {
        $this->fakeStripe();

        $payment = $this->pendingPayment();
        Billing::charge($payment);

        $this->assertSame(PaymentInitiation::Manual, $payment->fresh()->initiation);
        $this->assertTrue($payment->fresh()->isManual());
    }

    public function test_a_saved_card_charge_is_recorded_as_automatic(): void
    {
        $this->fakeStripe();

        $payment = $this->pendingPayment();
        Billing::chargeWithMethod($payment, $this->savedCard($payment));

        $this->assertSame(PaymentInitiation::Automatic, $payment->fresh()->initiation);
        $this->assertTrue($payment->fresh()->isAutomatic());
    }

    /**
     * The mechanism and the initiator can disagree: a one-click "pay with the saved card" button
     * is the off-session code path, but a person is standing right there.
     */
    public function test_the_caller_can_override_the_default_for_its_path(): void
    {
        $this->fakeStripe();

        $payment = $this->pendingPayment();
        Billing::chargeWithMethod(
            $payment,
            $this->savedCard($payment),
            new ChargeOptions(initiation: PaymentInitiation::Manual),
        );

        $this->assertSame(PaymentInitiation::Manual, $payment->fresh()->initiation);
    }

    /** Unknown is not a claim either way — a row the package never charged answers false to both. */
    public function test_a_payment_never_charged_claims_neither(): void
    {
        $payment = $this->pendingPayment();

        $this->assertNull($payment->initiation);
        $this->assertFalse($payment->isManual());
        $this->assertFalse($payment->isAutomatic());
    }

    private function fakeStripe(): void
    {
        Http::fake([
            // charge() opens a hosted Checkout session, chargeWithMethod() goes straight to an intent
            'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
            'https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'processing']),
        ]);
    }

    private function pendingPayment(): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'stripe',
            'amount' => 10000,
            'currency' => 'USD',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }

    private function savedCard(Payment $payment): PaymentMethod
    {
        return PaymentMethod::create([
            'gateway' => 'stripe',
            'external_customer_id' => 'cus_test',
            'external_id' => 'tok_test',
            'is_default' => true,
            'billable_type' => $payment->billable_type,
            'billable_id' => $payment->billable_id,
        ]);
    }
}
