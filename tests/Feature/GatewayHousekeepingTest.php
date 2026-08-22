<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Http;

class GatewayHousekeepingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'test_merch');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'secret');
    }

    /** An abandoned checkout is not a declined card — calling it one puts a subscription into dunning. */
    public function test_an_expired_wayforpay_checkout_is_canceled_not_failed(): void
    {
        $payment = $this->payment('wayforpay', 'UAH');

        $result = Billing::driver('wayforpay')->handleWebhook(new BillingWebhookCall(['name' => 'wayforpay', 'payload' => [
            'orderReference' => (string) $payment->id,
            'transactionStatus' => 'Expired',
        ]]));

        $this->assertSame('canceled', $result->status);
        $this->assertSame(PaymentStatus::Canceled, $payment->fresh()->status);
    }

    /** A re-delivered webhook for a card the customer has since replaced must not restore it as default. */
    public function test_a_redelivered_attach_does_not_move_the_default_back_to_an_old_card(): void
    {
        // One customer, two checkouts — the default is scoped per billable+gateway.
        $user = TestUser::create(['name' => 'Buyer']);
        $payment = $this->payment('wayforpay', 'UAH', billable: $user);

        $oldCard = ['orderReference' => (string) $payment->id, 'transactionStatus' => 'Approved',
            'amount' => 50.0, 'currency' => 'UAH', 'recToken' => 'rec_old'];

        Billing::driver('wayforpay')->handleWebhook(new BillingWebhookCall(['name' => 'wayforpay', 'payload' => $oldCard]));

        // The customer saves a newer card, which becomes the default.
        $newer = $this->payment('wayforpay', 'UAH', billable: $user);
        Billing::driver('wayforpay')->handleWebhook(new BillingWebhookCall(['name' => 'wayforpay', 'payload' => [
            'orderReference' => (string) $newer->id, 'transactionStatus' => 'Approved',
            'amount' => 50.0, 'currency' => 'UAH', 'recToken' => 'rec_new',
        ]]));

        $this->assertTrue(PaymentMethod::query()->where('external_id', 'rec_new')->sole()->is_default);

        // WayForPay re-delivers the first callback days later.
        Billing::driver('wayforpay')->handleWebhook(new BillingWebhookCall(['name' => 'wayforpay', 'payload' => $oldCard]));

        $this->assertTrue(PaymentMethod::query()->where('external_id', 'rec_new')->sole()->is_default);
        $this->assertFalse(PaymentMethod::query()->where('external_id', 'rec_old')->sole()->is_default);
        $this->assertSame(1, PaymentMethod::query()->where('is_default', true)->count());
    }

    public function test_a_synchronous_decline_reason_is_stored_on_the_payment(): void
    {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response([
            'error' => ['type' => 'card_error', 'code' => 'card_declined', 'payment_intent' => ['id' => 'pi_1']],
        ], 402)]);

        $payment = $this->payment('stripe', 'USD');
        $method = PaymentMethod::create([
            'gateway' => 'stripe',
            'external_customer_id' => 'cus_1',
            'external_id' => 'pm_1',
            'is_default' => true,
            'billable_type' => $payment->billable_type,
            'billable_id' => $payment->billable_id,
        ]);

        Billing::chargeWithMethod($payment, $method);

        $this->assertSame('card_declined', $payment->fresh()->raw_response['error']['code']);
    }

    /** A fetch failure answers "couldn't verify" (403) instead of taking the webhook route down. */
    public function test_an_unreachable_monobank_pubkey_endpoint_rejects_instead_of_erroring(): void
    {
        config(['billing.gateways.monobank.token' => 'mono_test']);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

        $this->postJson(route('billing.webhook', ['gateway' => 'monobank']), ['reference' => 'x'], [
            'X-Sign' => base64_encode('nonsense'),
        ])->assertForbidden();
    }

    public function test_an_expired_wayforpay_poll_is_canceled_too(): void
    {
        Http::fake(['https://api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Expired'])]);

        $payment = $this->payment('wayforpay', 'UAH', ['created_at' => now()->subHours(2)]);

        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Canceled, $payment->fresh()->status);
    }

    private function payment(string $gateway, string $currency, array $attributes = [], ?TestUser $billable = null): Payment
    {
        $user = $billable ?? TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
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
