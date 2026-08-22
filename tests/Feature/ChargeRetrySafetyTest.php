<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Support\Money;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Laravel's Http::retry() fires on a ConnectionException too — and a timeout says nothing about
 * whether the bank already moved the money. Anything that debits or refunds a card must therefore
 * be sent exactly once, unless the gateway offers an idempotency key to make a retry safe.
 */
class ChargeRetrySafetyTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
        $app['config']->set('billing.gateways.monobank.token', 'mono_test');
        $app['config']->set('billing.gateways.liqpay.public_key', 'pub');
        $app['config']->set('billing.gateways.liqpay.private_key', 'priv');
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'test_merch');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'secret');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.hutko.merchant_id', '1396424');
        $app['config']->set('billing.gateways.hutko.secret_key', 'test');
    }

    public static function offSessionGateways(): array
    {
        return [
            'monobank' => ['monobank', 'UAH'],
            'liqpay' => ['liqpay', 'UAH'],
            'wayforpay' => ['wayforpay', 'UAH'],
            'hutko' => ['hutko', 'UAH'],
            'stripe' => ['stripe', 'USD'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('offSessionGateways')]
    public function test_an_off_session_charge_is_never_sent_twice(string $gateway, string $currency): void
    {
        // A thrown connection failure is never recorded by Http::assertSentCount() — count the
        // attempts in the fake itself.
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('timed out');
        });

        $payment = $this->pendingPayment($gateway, $currency);
        $method = $this->savedCard($payment, $gateway);

        try {
            Billing::chargeWithMethod($payment, $method);
        } catch (ConnectionException) {
            // the timeout under test
        }

        $this->assertSame(1, $attempts, "{$gateway} retried a charge after a timeout");
    }

    public function test_a_refund_is_never_sent_twice(): void
    {
        foreach (['monobank' => 'UAH', 'liqpay' => 'UAH'] as $gateway => $currency) {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('timed out');
            });

            $payment = $this->paidPayment($gateway, $currency);

            try {
                Billing::refund($payment, new Money(1000, $currency));
            } catch (ConnectionException) {
                // the timeout under test
            }

            $this->assertSame(1, $attempts, "{$gateway} retried a refund after a timeout");
        }
    }

    /** Stripe is the exception: an idempotency key makes its retry safe, so the header must be there. */
    public function test_stripe_sends_an_idempotency_key_on_charges_and_refunds(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'processing']),
            'https://api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded']),
        ]);

        $payment = $this->pendingPayment('stripe', 'USD');
        Billing::chargeWithMethod($payment, $this->savedCard($payment, 'stripe'));

        $refundable = $this->paidPayment('stripe', 'USD');
        Billing::refund($refundable, new Money(1000, 'USD'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/payment_intents')
            && $request->header('Idempotency-Key') === ["charge-{$payment->id}"]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/refunds')
            && str_starts_with($request->header('Idempotency-Key')[0] ?? '', 'refund-'));
    }

    private function savedCard(Payment $payment, string $gateway): PaymentMethod
    {
        return PaymentMethod::create([
            'gateway' => $gateway,
            'external_customer_id' => 'cus_test',
            'external_id' => 'tok_test',
            'is_default' => true,
            'billable_type' => $payment->billable_type,
            'billable_id' => $payment->billable_id,
        ]);
    }

    private function pendingPayment(string $gateway, string $currency): Payment
    {
        return $this->payment($gateway, $currency, 'pending');
    }

    private function paidPayment(string $gateway, string $currency): Payment
    {
        return $this->payment($gateway, $currency, 'paid', ['external_id' => 'ext_1']);
    }

    private function payment(string $gateway, string $currency, string $status, array $attributes = []): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => $status,
            'type' => 'charge',
            'gateway' => $gateway,
            'amount' => 5000,
            'currency' => $currency,
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
