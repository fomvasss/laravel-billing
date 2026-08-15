<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * The security boundary of the whole webhook pipeline. Two properties under test: (1) real
 * gateway callbacks verify — including WayForPay's raw-JSON-under-a-form-content-type quirk and
 * Hutko's payload-wide signature with query extras on the URL; (2) validators fail CLOSED — an
 * unconfigured gateway's route rejects instead of verifying against an empty secret, which an
 * attacker could compute themselves.
 */
class WebhookSignatureValidationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.liqpay.public_key', 'pub_test');
        $app['config']->set('billing.gateways.liqpay.private_key', 'priv_test');
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'test_merchant');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'secret_test');
        $app['config']->set('billing.gateways.hutko.merchant_id', '1');
        $app['config']->set('billing.gateways.hutko.secret_key', 'hutko_secret');
        $app['config']->set('billing.gateways.stripe.webhook_secret', 'whsec_test');
    }

    public function test_a_wayforpay_callback_posted_as_raw_json_under_a_form_content_type_is_accepted(): void
    {
        $payment = $this->pendingPayment('wayforpay');

        $payload = [
            'merchantAccount' => 'test_merchant',
            'orderReference' => (string) $payment->id,
            'amount' => '100.00',
            'currency' => 'UAH',
            'authCode' => '123456',
            'cardPan' => '41****1234',
            'transactionStatus' => 'Approved',
            'reasonCode' => '1100',
        ];
        $payload['merchantSignature'] = hash_hmac('md5', implode(';', [
            $payload['merchantAccount'], $payload['orderReference'], $payload['amount'],
            $payload['currency'], $payload['authCode'], $payload['cardPan'],
            $payload['transactionStatus'], $payload['reasonCode'],
        ]), 'secret_test');

        // WayForPay's actual delivery shape: raw JSON body, form-urlencoded content type — PHP's
        // form parser turns it into one garbled key, so nothing here may rely on $request->input().
        $response = $this->call(
            'POST',
            route('billing.webhook', ['gateway' => 'wayforpay']),
            [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            json_encode($payload),
        );

        $response->assertOk();
        $this->assertSame('paid', $payment->fresh()->status->value);

        // The acknowledgment must be the signed accept body, or WayForPay retries for 4 days.
        $json = $response->json();
        $this->assertSame('accept', $json['status']);
        $this->assertSame((string) $payment->id, $json['orderReference']);
        $this->assertSame(
            hash_hmac('md5', implode(';', [$payment->id, 'accept', $json['time']]), 'secret_test'),
            $json['signature'],
        );
    }

    public function test_a_wayforpay_callback_with_a_bad_signature_is_rejected(): void
    {
        $payment = $this->pendingPayment('wayforpay');

        $response = $this->call(
            'POST',
            route('billing.webhook', ['gateway' => 'wayforpay']),
            [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            json_encode(['orderReference' => (string) $payment->id, 'transactionStatus' => 'Approved', 'merchantSignature' => 'forged']),
        );

        $response->assertForbidden();
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_a_liqpay_callback_with_a_valid_signature_is_accepted(): void
    {
        $data = base64_encode(json_encode(['order_id' => 'unknown', 'status' => 'success']));

        $this->post(route('billing.webhook', ['gateway' => 'liqpay']), [
            'data' => $data,
            'signature' => base64_encode(sha1('priv_test' . $data . 'priv_test', true)),
        ])->assertOk();
    }

    public function test_a_hutko_callback_verifies_against_the_body_even_with_query_extras_on_the_url(): void
    {
        $fields = ['order_id' => 'unknown', 'order_status' => 'processing', 'amount' => '100'];
        ksort($fields);

        $string = 'hutko_secret';
        foreach ($fields as $value) {
            $string .= '|' . $value;
        }

        // webhookUrlParams routing extras land on the URL — they must NOT leak into the
        // payload-wide signature computation.
        $this->postJson(
            route('billing.webhook', ['gateway' => 'hutko', 'shop' => 'main']),
            [...$fields, 'signature' => sha1($string)],
        )->assertOk();
    }

    public function test_a_stripe_event_with_a_valid_signature_is_accepted(): void
    {
        $body = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1', 'metadata' => []]]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test');

        $this->call(
            'POST',
            route('billing.webhook', ['gateway' => 'stripe']),
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
            $body,
        )->assertOk();
    }

    public function test_unconfigured_gateways_fail_closed_instead_of_verifying_against_an_empty_secret(): void
    {
        config()->set('billing.gateways.liqpay.private_key', null);
        config()->set('billing.gateways.wayforpay.secret_key', null);
        config()->set('billing.gateways.hutko.secret_key', null);
        config()->set('billing.gateways.stripe.webhook_secret', null);
        config()->set('billing.gateways.monobank.token', null);

        // Each request carries the exact "signature" an attacker could compute with an empty key.
        $data = base64_encode(json_encode(['order_id' => 'x', 'status' => 'success']));
        $this->post(route('billing.webhook', ['gateway' => 'liqpay']), [
            'data' => $data,
            'signature' => base64_encode(sha1($data, true)),
        ])->assertForbidden();

        $payload = [
            'merchantAccount' => 'm', 'orderReference' => 'x', 'amount' => '1.00', 'currency' => 'UAH',
            'authCode' => '', 'cardPan' => '', 'transactionStatus' => 'Approved', 'reasonCode' => '',
        ];
        $payload['merchantSignature'] = hash_hmac('md5', implode(';', array_values($payload)), '');
        $this->call('POST', route('billing.webhook', ['gateway' => 'wayforpay']), [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], json_encode($payload))->assertForbidden();

        $fields = ['amount' => '100', 'order_id' => 'x', 'order_status' => 'approved'];
        $string = '';
        foreach ($fields as $value) {
            $string .= '|' . $value;
        }
        $this->postJson(route('billing.webhook', ['gateway' => 'hutko']), [...$fields, 'signature' => sha1($string)])
            ->assertForbidden();

        $body = json_encode(['type' => 'payment_intent.succeeded']);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", '');
        $this->call('POST', route('billing.webhook', ['gateway' => 'stripe']), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"], $body)
            ->assertForbidden();

        // Monobank without a token can't even fetch the pubkey — reject without any HTTP call.
        Http::fake();
        $this->call('POST', route('billing.webhook', ['gateway' => 'monobank']), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGN' => base64_encode('junk')], json_encode(['status' => 'success']))
            ->assertForbidden();
        Http::assertNothingSent();
    }

    private function pendingPayment(string $gateway): Payment
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
