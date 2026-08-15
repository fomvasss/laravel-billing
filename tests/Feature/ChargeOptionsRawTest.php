<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * ChargeOptions::$raw is the escape hatch for gateway-specific fields the package has no neutral
 * shape for — LiqPay's rro_info (fiscalization) is the canonical case. It's merged first, so a
 * driver's own fields always win: raw can add what the driver doesn't set, never override the
 * amount or the merchant reference the webhook matches on.
 */
class ChargeOptionsRawTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.liqpay.public_key', 'pub_test');
        $app['config']->set('billing.gateways.liqpay.private_key', 'priv_test');
        $app['config']->set('billing.gateways.monobank.token', 'test-token');
    }

    public function test_liqpay_passes_rro_info_through_for_fiscalization(): void
    {
        $payment = $this->payment('liqpay');

        $rroInfo = ['items' => [['amount' => 2, 'price' => 202, 'cost' => 404, 'id' => 123456]]];

        $result = Billing::driver('liqpay')->charge($payment, new ChargeOptions(raw: ['rro_info' => $rroInfo]));

        $decoded = json_decode(base64_decode($result->form['fields']['data']), true);

        $this->assertSame($rroInfo, $decoded['rro_info']);
    }

    public function test_raw_cannot_override_the_amount_or_merchant_reference(): void
    {
        $payment = $this->payment('liqpay');

        $result = Billing::driver('liqpay')->charge($payment, new ChargeOptions(
            raw: ['amount' => '0.01', 'order_id' => 'attacker-controlled'],
        ));

        $decoded = json_decode(base64_decode($result->form['fields']['data']), true);

        $this->assertSame('100.00', $decoded['amount']);
        $this->assertSame((string) $payment->id, $decoded['order_id']);
    }

    public function test_monobank_passes_gateway_specific_extras_through(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response(['invoiceId' => 'inv_1', 'pageUrl' => 'https://pay.test/1']),
        ]);

        $payment = $this->payment('monobank');

        Billing::driver('monobank')->charge($payment, new ChargeOptions(raw: ['agentFeePercent' => 1.42]));

        Http::assertSent(fn ($request) => $request['agentFeePercent'] === 1.42
            && $request['amount'] === 10000
            && $request['merchantPaymInfo']['reference'] === (string) $payment->id);
    }

    private function payment(string $gateway): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => $gateway,
            'amount' => 10000,
            'currency_code' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
