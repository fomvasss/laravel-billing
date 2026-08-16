<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * ChecksGatewayHealth: a live, side-effect-free probe. The discriminating responses faked here are
 * the LIVE-verified ones (see each driver's healthCheck() docblock): "order not found" shapes mean
 * the credentials work, "invalid signature" shapes mean they don't.
 */
class GatewayHealthTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.monobank.token', 'test-token');
        $app['config']->set('billing.gateways.liqpay.public_key', 'pub');
        $app['config']->set('billing.gateways.liqpay.private_key', 'priv');
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'acc');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'sec');
        $app['config']->set('billing.gateways.hutko.merchant_id', '1');
        $app['config']->set('billing.gateways.hutko.secret_key', 'sec');
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test');
    }

    public function test_up_when_the_probe_recognizes_the_credentials(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/details' => Http::response(['merchantId' => 'm1', 'merchantName' => 'Test Shop']),
            'https://www.liqpay.ua/api/request' => Http::response(['status' => 'error', 'err_code' => 'payment_not_found']),
            'https://api.wayforpay.com/api' => Http::response(['reasonCode' => 1127, 'reason' => 'Order Not Found']),
            'https://pay.hutko.org/api/status/order_id' => Http::response(['response' => ['response_status' => 'failure', 'error_code' => 1018, 'error_message' => 'Order not found']]),
            'https://api.stripe.com/v1/balance' => Http::response(['livemode' => false]),
        ]);

        foreach (['monobank', 'liqpay', 'wayforpay', 'hutko', 'stripe', 'fake'] as $gateway) {
            $health = Billing::health($gateway);
            $this->assertTrue($health->ok, "{$gateway}: {$health->message}");
        }

        $this->assertSame('Test Shop', Billing::health('monobank')->message);
    }

    public function test_down_on_auth_shaped_errors_with_the_gateway_reason(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/details' => Http::response(['errText' => 'forbidden'], 403),
            'https://www.liqpay.ua/api/request' => Http::response(['status' => 'error', 'err_code' => 'invalid_signature', 'err_description' => 'Невірний підпис signature']),
            'https://api.wayforpay.com/api' => Http::response(['reasonCode' => 1113, 'reason' => 'Invalid signature']),
            'https://pay.hutko.org/api/status/order_id' => Http::response(['response' => ['response_status' => 'failure', 'error_code' => 1014, 'error_message' => 'Invalid signature']]),
        ]);

        $this->assertFalse(Billing::health('monobank')->ok);
        $this->assertStringContainsString('підпис', Billing::health('liqpay')->message);
        $this->assertStringContainsString('1113', Billing::health('wayforpay')->message);
        $this->assertStringContainsString('1014', Billing::health('hutko')->message);
    }

    public function test_the_health_command_reports_all_and_fails_when_one_is_down(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/details' => Http::response(['errText' => 'forbidden'], 403),
            'https://www.liqpay.ua/api/request' => Http::response(['status' => 'error', 'err_code' => 'payment_not_found']),
            'https://api.wayforpay.com/api' => Http::response(['reasonCode' => 1127, 'reason' => 'Order Not Found']),
            'https://pay.hutko.org/api/status/order_id' => Http::response(['response' => ['error_code' => 1018]]),
            'https://api.stripe.com/v1/balance' => Http::response(['livemode' => false]),
        ]);

        $this->artisan('billing:health')
            ->expectsOutputToContain('monobank')
            ->assertFailed(); // one gateway down → non-zero exit for monitoring
    }

    public function test_the_health_command_for_a_single_healthy_gateway_succeeds(): void
    {
        $this->artisan('billing:health', ['gateway' => 'fake'])->assertSuccessful();
    }
}
