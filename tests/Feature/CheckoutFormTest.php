<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

/**
 * BillingManager::charge() always writes payment_url as a plain redirectable link — for a
 * form-only gateway (LiqPay), that means bridging PaymentResult::$form through
 * CheckoutFormController rather than leaving payment_url null. See "PaymentResult — url чи form"
 * in the package plan.
 */
class CheckoutFormTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.liqpay.public_key', 'pub_test');
        $app['config']->set('billing.gateways.liqpay.private_key', 'priv_test');
    }

    public function test_charging_a_liqpay_payment_writes_a_checkout_form_url_not_null(): void
    {
        $payment = $this->pendingLiqPayPayment();

        Billing::charge($payment);

        $payment->refresh();
        $this->assertNotNull($payment->payment_url);
        $this->assertStringContainsString('/billing/checkout/' . $payment->id, $payment->payment_url);
        // Must mirror the cached form's TTL — hasActivePaymentUrl() may not outlive the cache entry.
        $this->assertNotNull($payment->payment_url_expires_at);
    }

    public function test_visiting_the_checkout_form_url_renders_the_liqpay_form(): void
    {
        $payment = $this->pendingLiqPayPayment();
        Billing::charge($payment);
        $payment->refresh();

        $response = $this->get($payment->payment_url);

        $response->assertOk();
        $response->assertSee('https://www.liqpay.ua/api/3/checkout', false);
        $response->assertSee('name="data"', false);
        $response->assertSee('name="signature"', false);
    }

    public function test_visiting_an_expired_or_unknown_checkout_form_url_is_a_404(): void
    {
        $payment = $this->pendingLiqPayPayment();

        $response = $this->get(route('billing.checkout-form', $payment));

        $response->assertNotFound();
    }

    public function test_charging_a_url_gateway_still_writes_its_own_url_directly(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $payment = Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::charge($payment);

        $payment->refresh();
        $this->assertStringContainsString('/billing/fake/' . $payment->id, $payment->payment_url);
    }

    private function pendingLiqPayPayment(): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

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
