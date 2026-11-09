<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Events\PaymentCanceled;
use Fomvasss\Billing\Events\PaymentVoided;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Exceptions\NotSupportedException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Jobs\ProcessWebhookJob;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Billing::voidPayment() — the merchant withdraws an unpaid charge: the gateway invalidates the
 * checkout, the row is `canceled` + `voided_at`, and the pay link never re-issues it.
 */
class VoidPaymentTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.monobank.token', 'test-token');
        $app['config']->set('billing.gateways.liqpay.public_key', 'pub');
        $app['config']->set('billing.gateways.liqpay.private_key', 'priv');
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
        $app['config']->set('billing.gateways.paddle.api_key', 'pdl_sdbx_apikey_test');
    }

    public function test_voiding_invalidates_the_monobank_invoice(): void
    {
        Event::fake([PaymentVoided::class, PaymentCanceled::class]);
        Http::fake(['api.monobank.ua/api/merchant/invoice/remove' => Http::response([])]);

        $payment = Billing::voidPayment($this->pendingPayment());

        $this->assertSame(PaymentStatus::Canceled, $payment->status);
        $this->assertTrue($payment->isVoided());
        $this->assertNull($payment->payment_url);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/remove'
            && $request['invoiceId'] === 'inv_1');
        Event::assertDispatchedTimes(PaymentVoided::class, 1);
        // PaymentCanceled would put a renewal into dunning
        Event::assertNotDispatched(PaymentCanceled::class);
    }

    public function test_an_invoice_already_expired_on_monobank_is_voided(): void
    {
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/remove' => Http::response(['errCode' => 'BAD_REQUEST'], 400),
            'api.monobank.ua/api/merchant/invoice/status*' => Http::response(['status' => 'expired']),
        ]);

        $this->assertTrue(Billing::voidPayment($this->pendingPayment())->isVoided());
    }

    public function test_an_invoice_being_paid_is_not_voided(): void
    {
        Event::fake([PaymentVoided::class]);
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/remove' => Http::response(['errCode' => 'BAD_REQUEST'], 400),
            'api.monobank.ua/api/merchant/invoice/status*' => Http::response(['status' => 'processing']),
        ]);
        $payment = $this->pendingPayment();

        try {
            Billing::voidPayment($payment);
            $this->fail('A processing invoice must not be voided.');
        } catch (BillingException $e) {
            $this->assertStringContainsString('processing', $e->getMessage());
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertFalse($payment->isVoided());
        $this->assertNotNull($payment->payment_url);
        Event::assertNotDispatched(PaymentVoided::class);
    }

    public function test_a_payment_the_gateway_never_saw_is_voided_without_an_api_call(): void
    {
        Http::fake();

        $payment = Billing::voidPayment($this->pendingPayment(['external_id' => null, 'payment_url' => null]));

        $this->assertTrue($payment->isVoided());
        Http::assertNothingSent();
    }

    public function test_voiding_twice_calls_the_gateway_once(): void
    {
        Event::fake([PaymentVoided::class]);
        Http::fake(['api.monobank.ua/api/merchant/invoice/remove' => Http::response([])]);
        $payment = $this->pendingPayment();

        Billing::voidPayment($payment);
        Billing::voidPayment($payment);

        Http::assertSentCount(1);
        Event::assertDispatchedTimes(PaymentVoided::class, 1);
    }

    public function test_a_paid_payment_is_refused(): void
    {
        Http::fake();

        $this->expectException(BillingException::class);

        Billing::voidPayment($this->pendingPayment(['status' => 'paid']));
    }

    public function test_an_off_session_charge_awaiting_its_webhook_is_refused(): void
    {
        Http::fake();

        $this->expectException(BillingException::class);

        Billing::voidPayment($this->pendingPayment(['payment_url' => null]));
    }

    public function test_a_gateway_without_the_capability_is_refused_rather_than_voided_locally(): void
    {
        Http::fake();
        $payment = $this->pendingPayment(['gateway' => 'liqpay']);

        try {
            Billing::voidPayment($payment);
            $this->fail('A live checkout on a driver without VoidsPayments must not be voided locally.');
        } catch (NotSupportedException) {
        }

        $this->assertFalse($payment->fresh()->isVoided());
    }

    public function test_an_open_checkout_without_a_gateway_reference_still_needs_the_capability(): void
    {
        Http::fake();
        $payment = $this->pendingPayment(['gateway' => 'liqpay', 'external_id' => null]);

        $this->expectException(NotSupportedException::class);

        Billing::voidPayment($payment);
    }

    public function test_a_late_decline_does_not_reopen_a_voided_payment(): void
    {
        Http::fake(['api.monobank.ua/api/merchant/invoice/remove' => Http::response([])]);
        $payment = Billing::voidPayment($this->pendingPayment());

        $this->webhook($payment, ['status' => 'failure', 'errCode' => '59', 'failureReason' => 'Insufficient funds']);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Canceled, $payment->status);
        $this->assertNull($payment->failure_code);
    }

    public function test_money_that_did_arrive_for_a_voided_payment_is_recorded(): void
    {
        Http::fake(['api.monobank.ua/api/merchant/invoice/remove' => Http::response([])]);
        $payment = Billing::voidPayment($this->pendingPayment());

        $this->webhook($payment, ['status' => 'success', 'amount' => 10000, 'ccy' => 980]);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_the_pay_link_of_a_voided_payment_lands_on_the_failed_page(): void
    {
        Http::fake(['api.monobank.ua/api/merchant/invoice/remove' => Http::response([])]);
        $payment = Billing::voidPayment($this->pendingPayment());

        $this->get(route('billing.pay', $payment))
            ->assertRedirect('https://example.test/fail?payment=' . $payment->id . '&status=canceled');

        // no fresh checkout was opened
        Http::assertSentCount(1);
    }

    public function test_an_expired_canceled_payment_is_still_reissued_by_the_pay_link(): void
    {
        Http::fake(['api.monobank.ua/api/merchant/invoice/create' => Http::response(['invoiceId' => 'inv_2', 'pageUrl' => 'https://pay.mbnk.biz/inv_2'])]);
        $payment = $this->pendingPayment(['status' => 'canceled', 'payment_url_expires_at' => now()->subHour()]);

        $this->get(route('billing.pay', $payment))->assertRedirect('https://pay.mbnk.biz/inv_2');
    }

    public function test_charge_refuses_a_voided_payment(): void
    {
        Http::fake(['api.monobank.ua/api/merchant/invoice/remove' => Http::response([])]);
        $payment = Billing::voidPayment($this->pendingPayment());

        $this->expectException(BillingException::class);

        Billing::charge($payment);
    }

    public function test_the_fake_gateway_voids_and_its_page_refuses_the_payment(): void
    {
        $payment = Billing::voidPayment($this->pendingPayment(['gateway' => 'fake', 'external_id' => null]));

        $this->assertTrue($payment->isVoided());
        $this->get(route('billing.fake.show', $payment))->assertStatus(410);
    }

    public function test_the_cached_checkout_form_of_a_voided_payment_is_gone(): void
    {
        $payment = $this->pendingPayment(['gateway' => 'fake', 'external_id' => null]);
        \Illuminate\Support\Facades\Cache::put("billing.checkout_form.{$payment->id}", ['action' => 'https://example.test', 'fields' => []], now()->addHour());

        Billing::voidPayment($payment);

        $this->get(route('billing.checkout-form', $payment))->assertStatus(410);
        $this->assertNull(\Illuminate\Support\Facades\Cache::get("billing.checkout_form.{$payment->id}"));
    }

    public function test_stripe_expires_the_checkout_session(): void
    {
        Http::fake(['https://api.stripe.com/v1/checkout/sessions/cs_1/expire' => Http::response(['id' => 'cs_1', 'status' => 'expired'])]);

        $payment = Billing::voidPayment($this->pendingPayment(['gateway' => 'stripe', 'external_id' => 'cs_1']));

        $this->assertTrue($payment->isVoided());
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.stripe.com/v1/checkout/sessions/cs_1/expire');
    }

    public function test_stripe_refuses_a_completed_session(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions/cs_1/expire' => Http::response(['error' => ['code' => 'checkout_session_not_expirable']], 400),
            'https://api.stripe.com/v1/checkout/sessions/cs_1' => Http::response(['id' => 'cs_1', 'status' => 'complete']),
        ]);
        $payment = $this->pendingPayment(['gateway' => 'stripe', 'external_id' => 'cs_1']);

        try {
            Billing::voidPayment($payment);
            $this->fail('A completed session must not be voided.');
        } catch (BillingException $e) {
            $this->assertStringContainsString('complete', $e->getMessage());
        }

        $this->assertFalse($payment->fresh()->isVoided());
    }

    public function test_paddle_cancels_the_transaction(): void
    {
        Http::fake(['https://sandbox-api.paddle.com/transactions/txn_1' => Http::response(['data' => ['id' => 'txn_1', 'status' => 'canceled']])]);

        $payment = Billing::voidPayment($this->pendingPayment(['gateway' => 'paddle', 'currency' => 'USD', 'external_id' => 'txn_1']));

        $this->assertTrue($payment->isVoided());
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['status'] === 'canceled');
    }

    public function test_paddle_refuses_a_billed_transaction(): void
    {
        Http::fake(function ($request) {
            return $request->method() === 'PATCH'
                ? Http::response(['error' => ['code' => 'transaction_immutable']], 400)
                : Http::response(['data' => ['id' => 'txn_1', 'status' => 'billed']]);
        });
        $payment = $this->pendingPayment(['gateway' => 'paddle', 'currency' => 'USD', 'external_id' => 'txn_1']);

        $this->expectException(BillingException::class);

        Billing::voidPayment($payment);
    }

    public function test_the_capability_is_listed(): void
    {
        $this->assertTrue(Billing::gateways()['monobank']['capabilities']['voids']);
        $this->assertTrue(Billing::gateways()['fake']['capabilities']['voids']);
        $this->assertTrue(Billing::gateways()['stripe']['capabilities']['voids']);
        $this->assertTrue(Billing::gateways()['paddle']['capabilities']['voids']);
        $this->assertFalse(Billing::gateways()['liqpay']['capabilities']['voids']);
    }

    private function pendingPayment(array $attributes = []): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'monobank',
            'amount' => 10000,
            'currency' => 'UAH',
            'external_id' => 'inv_1',
            'payment_url' => 'https://pay.mbnk.biz/inv_1',
            'payment_url_expires_at' => now()->addDay(),
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }

    private function webhook(Payment $payment, array $payload): void
    {
        ProcessWebhookJob::dispatch(BillingWebhookCall::create([
            'name' => 'monobank',
            'url' => 'https://example.test/billing/webhooks/monobank',
            'payload' => ['invoiceId' => 'inv_1', 'reference' => (string) $payment->id, ...$payload],
        ]));
    }
}
