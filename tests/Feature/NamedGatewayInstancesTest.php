<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Gateways\Hutko\HutkoGateway;
use Fomvasss\Billing\Gateways\Hutko\HutkoSignatureValidator;
use Fomvasss\Billing\Gateways\LiqPay\LiqPayGateway;
use Fomvasss\Billing\Gateways\LiqPay\LiqPaySignatureValidator;
use Fomvasss\Billing\Gateways\Monobank\MonobankGateway;
use Fomvasss\Billing\Gateways\Monobank\MonobankSignatureValidator;
use Fomvasss\Billing\Gateways\Paddle\PaddleGateway;
use Fomvasss\Billing\Gateways\Paddle\PaddleSignatureValidator;
use Fomvasss\Billing\Gateways\Stripe\StripeGateway;
use Fomvasss\Billing\Gateways\Stripe\StripeSignatureValidator;
use Fomvasss\Billing\Gateways\WayForPay\WayForPayGateway;
use Fomvasss\Billing\Gateways\WayForPay\WayForPaySignatureValidator;
use Fomvasss\Billing\Gateways\WayForPay\WayForPayWebhookResponder;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Two merchant accounts of one gateway, without tenants: the same driver class registered twice,
 * each name with its own config block. Charging already resolved credentials by name; webhooks
 * have to as well, or the second account's callbacks are checked against the first one's secret.
 */
class NamedGatewayInstancesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('billing.gateways.liqpay', ['public_key' => 'pub_main', 'private_key' => 'priv_main']);
        $app['config']->set('billing.gateways.liqpay_shop2', ['public_key' => 'pub_shop2', 'private_key' => 'priv_shop2']);
        $app['config']->set('billing.gateways.wayforpay', ['merchant_account' => 'main', 'merchant_domain' => 'main.test', 'secret_key' => 'wfp_main']);
        $app['config']->set('billing.gateways.wayforpay_shop2', ['merchant_account' => 'shop2', 'merchant_domain' => 'shop2.test', 'secret_key' => 'wfp_shop2']);
        $app['config']->set('billing.gateways.hutko', ['merchant_id' => '1', 'secret_key' => 'hutko_main']);
        $app['config']->set('billing.gateways.hutko_shop2', ['merchant_id' => '2', 'secret_key' => 'hutko_shop2']);
        $app['config']->set('billing.gateways.stripe', ['secret_key' => 'sk_main', 'webhook_secret' => 'whsec_main']);
        $app['config']->set('billing.gateways.stripe_shop2', ['secret_key' => 'sk_shop2', 'webhook_secret' => 'whsec_shop2']);
        $app['config']->set('billing.gateways.paddle', ['api_key' => 'pdl_sdbx_apikey_main', 'client_token' => 'test_main', 'webhook_secret' => 'pdl_ntfset_main']);
        $app['config']->set('billing.gateways.paddle_shop2', ['api_key' => 'pdl_sdbx_apikey_shop2', 'client_token' => 'test_shop2', 'webhook_secret' => 'pdl_ntfset_shop2']);
        $app['config']->set('billing.gateways.monobank', ['token' => 'mono_main']);
        $app['config']->set('billing.gateways.monobank_shop2', ['token' => 'mono_shop2']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(BillingManager::class)
            ->extend('liqpay_shop2', LiqPayGateway::class)->registerWebhook('liqpay_shop2', LiqPaySignatureValidator::class)
            ->extend('wayforpay_shop2', WayForPayGateway::class)->registerWebhook('wayforpay_shop2', WayForPaySignatureValidator::class, WayForPayWebhookResponder::class)
            ->extend('hutko_shop2', HutkoGateway::class)->registerWebhook('hutko_shop2', HutkoSignatureValidator::class)
            ->extend('stripe_shop2', StripeGateway::class)->registerWebhook('stripe_shop2', StripeSignatureValidator::class)
            ->extend('paddle_shop2', PaddleGateway::class)->registerWebhook('paddle_shop2', PaddleSignatureValidator::class)
            ->extend('monobank_shop2', MonobankGateway::class)->registerWebhook('monobank_shop2', MonobankSignatureValidator::class);
    }

    public function test_liqpay(): void
    {
        $data = base64_encode(json_encode(['order_id' => 'unknown', 'status' => 'success']));
        $signed = fn (string $key) => ['data' => $data, 'signature' => base64_encode(sha1($key . $data . $key, true))];

        $this->post(route('billing.webhook', ['gateway' => 'liqpay_shop2']), $signed('priv_shop2'))->assertOk();
        $this->post(route('billing.webhook', ['gateway' => 'liqpay_shop2']), $signed('priv_main'))->assertForbidden();
    }

    public function test_wayforpay_verifies_and_acknowledges_as_its_own_merchant(): void
    {
        $payload = ['merchantAccount' => 'shop2', 'orderReference' => 'unknown', 'amount' => '1.00', 'currency' => 'UAH',
            'authCode' => '1', 'cardPan' => '41**', 'transactionStatus' => 'Approved', 'reasonCode' => '1100'];
        $sign = fn (string $key) => [...$payload, 'merchantSignature' => hash_hmac('md5', implode(';', $payload), $key)];

        $json = $this->postJson(route('billing.webhook', ['gateway' => 'wayforpay_shop2']), $sign('wfp_shop2'))->assertOk()->json();
        $this->assertSame(hash_hmac('md5', implode(';', ['unknown', 'accept', $json['time']]), 'wfp_shop2'), $json['signature']);

        $this->postJson(route('billing.webhook', ['gateway' => 'wayforpay_shop2']), $sign('wfp_main'))->assertForbidden();
    }

    public function test_hutko(): void
    {
        $fields = ['amount' => '100', 'order_id' => 'unknown', 'order_status' => 'processing'];
        $sign = fn (string $key) => [...$fields, 'signature' => sha1($key . '|' . implode('|', $fields))];

        $this->postJson(route('billing.webhook', ['gateway' => 'hutko_shop2']), $sign('hutko_shop2'))->assertOk();
        $this->postJson(route('billing.webhook', ['gateway' => 'hutko_shop2']), $sign('hutko_main'))->assertForbidden();
    }

    public function test_stripe(): void
    {
        $body = json_encode(['type' => 'payment_intent.created', 'data' => ['object' => ['id' => 'pi_1', 'metadata' => []]]]);
        $post = fn (string $secret) => $this->call('POST', route('billing.webhook', ['gateway' => 'stripe_shop2']), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=' . time() . ',v1=' . hash_hmac('sha256', time() . ".{$body}", $secret)], $body);

        $post('whsec_shop2')->assertOk();
        $post('whsec_main')->assertForbidden();
    }

    public function test_paddle_webhooks(): void
    {
        $body = json_encode(['event_type' => 'transaction.created', 'data' => ['id' => 'txn_1']]);
        $post = fn (string $secret) => $this->call('POST', route('billing.webhook', ['gateway' => 'paddle_shop2']), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_PADDLE_SIGNATURE' => 'ts=' . time() . ';h1=' . hash_hmac('sha256', time() . ":{$body}", $secret)], $body);

        $post('pdl_ntfset_shop2')->assertOk();
        $post('pdl_ntfset_main')->assertForbidden();
    }

    public function test_the_paddle_checkout_page_uses_the_token_of_the_account_the_payment_went_through(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);
        Payment::create([
            'status' => 'pending', 'type' => 'charge', 'gateway' => 'paddle_shop2', 'amount' => 10000, 'currency' => 'UAH',
            'external_id' => 'txn_shop2', 'payable_type' => TestUser::class, 'payable_id' => $user->id,
            'billable_type' => TestUser::class, 'billable_id' => $user->id,
        ]);

        $this->get(route('billing.paddle.checkout') . '?_ptxn=txn_shop2')
            ->assertOk()
            ->assertSee('"test_shop2"', false)
            ->assertDontSee('"test_main"', false);
    }

    public function test_monobank_fetches_the_pubkey_with_its_own_token(): void
    {
        Http::fake(['https://api.monobank.ua/api/merchant/pubkey' => Http::response([], 500)]);

        $this->call('POST', route('billing.webhook', ['gateway' => 'monobank_shop2']), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGN' => base64_encode('sig')], json_encode(['invoiceId' => 'x']));

        Http::assertSent(fn ($request) => $request->hasHeader('X-Token', 'mono_shop2'));
        Http::assertNotSent(fn ($request) => $request->hasHeader('X-Token', 'mono_main'));
    }
}
