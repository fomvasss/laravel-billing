<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Http;

/** With an enforced morph map every row must carry the alias, not the class name. */
class MorphMapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Relation::enforceMorphMap(['user' => TestUser::class]);
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'test_merchant');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'secret_test');
    }

    public function test_a_manually_attached_method_can_charge_a_payment_of_the_same_billable(): void
    {
        Http::fake([
            'https://api.wayforpay.com/api' => Http::response(['orderReference' => 'x', 'transactionStatus' => 'Approved']),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $method = Billing::driver('wayforpay')->attachPaymentMethod($user, ['rec_token' => 'rec_tok_1']);

        $this->assertSame('user', $method->billable_type);
        $this->assertTrue($user->defaultPaymentMethod->is($method));

        Billing::chargeWithMethod($this->pendingPayment($user), $method);

        Http::assertSentCount(1);
    }

    public function test_a_webhook_for_an_already_attached_token_does_not_create_a_second_row(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);
        Billing::driver('wayforpay')->attachPaymentMethod($user, ['rec_token' => 'rec_tok_1']);
        $payment = $this->pendingPayment($user);

        Billing::driver('wayforpay')->handleWebhook(new BillingWebhookCall(['name' => 'wayforpay', 'payload' => [
            'orderReference' => (string) $payment->id,
            'transactionStatus' => 'Approved',
            'recToken' => 'rec_tok_1',
        ]]));

        $this->assertSame(1, PaymentMethod::query()->where('external_id', 'rec_tok_1')->count());
    }

    private function pendingPayment(TestUser $user): Payment
    {
        $payment = new Payment([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'wayforpay',
            'amount' => 10000,
            'currency' => 'UAH',
        ]);
        $payment->payable()->associate($user);
        $payment->billable()->associate($user);
        $payment->save();

        return $payment;
    }
}
