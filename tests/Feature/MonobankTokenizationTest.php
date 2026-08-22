<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Events\PaymentMethodAttached;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\PaymentMethodDetached;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Jobs\ProcessWebhookJob;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Unlike Stripe, a Monobank card token never arrives from a direct attachPaymentMethod() call —
 * it arrives asynchronously via handleWebhook()'s `walletData`, confirmed against `support`'s
 * production MonobankPaymentService (the same "charge once with saveCard, tokenize as a side
 * effect, get the token in a later webhook delivery" flow). See "Токенізація" in the package plan.
 */
class MonobankTokenizationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.monobank.token', 'test-token');
    }

    public function test_a_walletdata_webhook_auto_attaches_the_card_exactly_once(): void
    {
        Event::fake([PaymentMethodAttached::class]);

        $user = TestUser::create(['name' => 'Buyer']);
        $payment = $this->pendingMonobankPayment($user);

        $webhookCall = BillingWebhookCall::create([
            'name' => 'monobank',
            'url' => 'https://example.test/billing/webhooks/monobank',
            'payload' => [
                'invoiceId' => 'inv_1',
                'status' => 'success',
                'reference' => (string) $payment->id,
                'paymentInfo' => ['maskedPan' => '444403******1902', 'paymentSystem' => 'visa'],
                'walletData' => ['walletId' => 'wallet_1', 'cardToken' => 'card_tok_1', 'status' => 'created'],
            ],
        ]);

        ProcessWebhookJob::dispatch($webhookCall);

        $method = PaymentMethod::query()->where('gateway', 'monobank')->where('external_id', 'card_tok_1')->firstOrFail();
        $this->assertSame('wallet_1', $method->external_customer_id);
        $this->assertSame('visa', $method->brand);
        $this->assertSame('1902', $method->last4);
        $this->assertTrue($method->is_default);

        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);

        // The same delivery carries status=success — the card being attached must not swallow it,
        // or the payment sits pending until reconciliation an hour later.
        $this->assertSame('paid', $payment->fresh()->status->value);
    }

    public function test_a_card_attach_and_a_payment_outcome_in_one_delivery_both_take_effect(): void
    {
        Event::fake([PaymentMethodAttached::class, PaymentSucceeded::class]);

        $user = TestUser::create(['name' => 'Buyer']);
        $payment = $this->pendingMonobankPayment($user);

        ProcessWebhookJob::dispatch(BillingWebhookCall::create([
            'name' => 'monobank',
            'url' => 'https://example.test/billing/webhooks/monobank',
            'payload' => [
                'invoiceId' => 'inv_2',
                'status' => 'success',
                'amount' => $payment->amount,
                'ccy' => 980,
                'reference' => (string) $payment->id,
                'walletData' => ['walletId' => 'wallet_2', 'cardToken' => 'card_tok_3', 'status' => 'created'],
            ],
        ]));

        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame('inv_2', $payment->fresh()->external_id);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);
    }

    public function test_attaching_a_known_token_verifies_it_against_the_wallet_and_persists_it(): void
    {
        Event::fake([PaymentMethodAttached::class]);
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet*' => Http::response([
                'wallet' => [['cardToken' => 'card_tok_2', 'maskedPan' => '424242******4242', 'country' => '804']],
            ]),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);

        $method = Billing::driver('monobank')->attachPaymentMethod($user, ['card_token' => 'card_tok_2']);

        $this->assertSame('card_tok_2', $method->external_id);
        $this->assertSame('4242', $method->last4);
        Event::assertDispatchedTimes(PaymentMethodAttached::class, 1);
    }

    public function test_attaching_an_unknown_token_throws(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet*' => Http::response(['wallet' => []]),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);

        $this->expectException(\Fomvasss\Billing\Exceptions\BillingException::class);

        Billing::driver('monobank')->attachPaymentMethod($user, ['card_token' => 'card_tok_missing']);
    }

    public function test_charging_a_payment_method_calls_wallet_payment_with_merchant_initiation(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response(['invoiceId' => 'inv_2', 'status' => 'success']),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $payment = $this->pendingMonobankPayment($user);
        $method = PaymentMethod::create([
            'gateway' => 'monobank',
            'external_customer_id' => 'wallet_1',
            'external_id' => 'card_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $result = Billing::driver('monobank')->chargePaymentMethod($payment, $method);

        $this->assertSame('inv_2', $result->externalId);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.monobank.ua/api/merchant/wallet/payment'
            && $request['cardToken'] === 'card_tok_1'
            && $request['ccy'] === 980
            && $request['initiationKind'] === 'merchant');
    }

    public function test_charging_a_payment_method_with_receipt_items_sends_a_fiscal_basket(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response(['invoiceId' => 'inv_3', 'status' => 'success']),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $payment = $this->pendingMonobankPayment($user);
        $method = PaymentMethod::create([
            'gateway' => 'monobank',
            'external_customer_id' => 'wallet_1',
            'external_id' => 'card_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::driver('monobank')->chargePaymentMethod($payment, $method, new \Fomvasss\Billing\DTO\ChargeOptions(
            receiptItems: [['name' => 'Widget', 'qty' => 1, 'unitAmount' => 10000, 'sku' => 'WID-1']],
        ));

        Http::assertSent(fn ($request) => $request['merchantPaymInfo']['basketOrder'][0]['name'] === 'Widget'
            && $request['merchantPaymInfo']['basketOrder'][0]['code'] === 'WID-1');
    }

    public function test_detaching_a_payment_method_sends_the_card_token_as_a_query_parameter(): void
    {
        Event::fake([PaymentMethodDetached::class]);
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/card*' => Http::response([]),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $method = PaymentMethod::create([
            'gateway' => 'monobank',
            'external_customer_id' => 'wallet_1',
            'external_id' => 'card_tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::driver('monobank')->detachPaymentMethod($method);

        $this->assertDatabaseMissing('billing_payment_methods', ['id' => $method->id]);
        Event::assertDispatched(PaymentMethodDetached::class);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.monobank.ua/api/merchant/wallet/card')
            && $request->method() === 'DELETE'
            && str_contains($request->url(), 'cardToken=card_tok_1'));
    }

    public function test_charge_with_save_card_handles_an_int_billable_id_attribute(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response(['invoiceId' => 'inv_1', 'pageUrl' => 'https://pay.mbnk.biz/x']),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        // Freshly created, not refetched — billable_id is still an int attribute in memory (the
        // way a real controller passes it); strict-typed walletId(string, string) crashed on this.
        $payment = $this->pendingMonobankPayment($user);

        \Fomvasss\Billing\Facades\Billing::charge($payment, new \Fomvasss\Billing\DTO\ChargeOptions(saveCard: true));

        Http::assertSent(fn ($request) => isset($request['saveCardData']['walletId']));
    }

    private function pendingMonobankPayment(TestUser $user): Payment
    {
        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'monobank',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
