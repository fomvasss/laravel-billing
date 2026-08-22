<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * The customer's IP is a first-class option, not a magic key inside $options->raw. It used to be
 * the latter, and on Hutko that broke every off-session charge: raw is spread into the request, so
 * an `ip` key rode along as a field Hutko doesn't know — Hutko signed the eight fields it
 * recognized, the driver signed nine, and the charge came back "Invalid signature" (live-found).
 */
class CustomerIpTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.hutko.merchant_id', '1700002');
        $app['config']->set('billing.gateways.hutko.secret_key', 'secret_test');
        $app['config']->set('billing.gateways.liqpay.public_key', 'pub');
        $app['config']->set('billing.gateways.liqpay.private_key', 'priv');
    }

    public function test_hutko_sends_the_ip_as_client_ip_and_signs_exactly_what_it_sends(): void
    {
        Http::fake(['*' => Http::response(['response' => ['payment_id' => 1, 'order_status' => 'approved']])]);

        $payment = $this->payment('hutko');

        Billing::chargeWithMethod($payment, $this->savedCard($payment), new ChargeOptions(
            description: 'Off-session',
            customerIp: '203.0.113.7',
        ));

        Http::assertSent(function ($request) {
            $fields = $request['request'];

            $this->assertSame('203.0.113.7', $fields['client_ip']);
            $this->assertArrayNotHasKey('ip', $fields, 'a field Hutko does not know would break its signature');

            // Recompute Hutko's signature over exactly the fields sent, minus the signature itself.
            $signed = array_filter($fields, static fn ($v, $k) => $k !== 'signature' && $v !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);
            ksort($signed);
            $string = 'secret_test';

            foreach ($signed as $value) {
                $string .= '|' . (is_array($value) ? json_encode($value) : $value);
            }

            $this->assertSame(sha1($string), $fields['signature']);

            return true;
        });
    }

    public function test_liqpay_sends_the_ip_as_its_own_ip_field(): void
    {
        Http::fake(['*' => Http::response(['result' => 'ok', 'status' => 'success', 'payment_id' => 1])]);

        $payment = $this->payment('liqpay');

        Billing::chargeWithMethod($payment, $this->savedCard($payment), new ChargeOptions(customerIp: '203.0.113.7'));

        Http::assertSent(function ($request) {
            $sent = json_decode(base64_decode($request['data']), true);

            $this->assertSame('203.0.113.7', $sent['ip']);

            return true;
        });
    }

    private function savedCard(Payment $payment): PaymentMethod
    {
        return PaymentMethod::create([
            'gateway' => $payment->gateway,
            'external_customer_id' => 'cus_1',
            'external_id' => 'tok_1',
            'is_default' => true,
            'billable_type' => $payment->billable_type,
            'billable_id' => $payment->billable_id,
        ]);
    }

    private function payment(string $gateway): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => $gateway,
            'amount' => 700,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
