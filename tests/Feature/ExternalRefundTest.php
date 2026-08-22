<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentType;
use Fomvasss\Billing\Events\PaymentRefunded;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Jobs\ProcessWebhookJob;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Support\Money;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Money refunded outside Billing::refund() — from the gateway's own dashboard, or forced by a
 * cardholder dispute. Without recording it, refundedAmount() reports 0 for money that has already
 * left the merchant account.
 */
class ExternalRefundTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
        $app['config']->set('billing.gateways.monobank.token', 'mono_test');
    }

    public function test_a_dashboard_refund_is_recorded_against_the_charge(): void
    {
        Event::fake([PaymentRefunded::class]);

        $charge = $this->paidPayment('stripe', 'pi_1');

        // Through the whole pipeline, not just the driver: PaymentRefunded is fired by the
        // dispatcher, and it has to carry the refund row rather than the original charge.
        $call = $this->stripeRefund($charge, 10000, 're_1');
        $call->save();
        ProcessWebhookJob::dispatch($call);

        $this->assertSame(10000, $charge->refundedAmount());

        $refund = $charge->refunds()->sole();
        $this->assertSame(PaymentType::Refund, $refund->type);
        $this->assertSame('re_1', $refund->external_id);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Event::assertDispatched(PaymentRefunded::class, fn ($event) => $event->payment->is($refund));
    }

    public function test_a_redelivered_refund_callback_does_not_record_it_twice(): void
    {
        $charge = $this->paidPayment('stripe', 'pi_1');

        Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 10000, 're_1'));
        $result = Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 10000, 're_1'));

        $this->assertSame('ignored', $result->status);
        $this->assertSame(10000, $charge->refundedAmount());
    }

    /** The gateway reports its running total, so a second partial settles to the difference. */
    public function test_a_second_partial_refund_records_only_the_difference(): void
    {
        $charge = $this->paidPayment('stripe', 'pi_1');

        Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 3000, 're_1'));
        Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 8000, 're_2'));

        $this->assertSame(8000, $charge->refundedAmount());
        $this->assertSame([3000, 5000], $charge->refunds()->orderBy('created_at')->pluck('amount')->all());
    }

    /** Our own refund() already recorded it — the callback that echoes it must add nothing. */
    public function test_the_callback_echoing_our_own_refund_records_nothing(): void
    {
        Http::fake(['https://api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'])]);

        $charge = $this->paidPayment('stripe', 'pi_1');
        Billing::refund($charge, new Money(10000, 'UAH'));

        $result = Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 10000, 're_1'));

        $this->assertSame('ignored', $result->status);
        $this->assertSame(1, $charge->refunds()->count());
    }

    public function test_a_monobank_reversal_is_recorded_from_its_cancel_list(): void
    {
        $charge = $this->paidPayment('monobank', 'inv_1');

        $result = Billing::driver('monobank')->handleWebhook(new BillingWebhookCall(['name' => 'monobank', 'payload' => [
            'reference' => (string) $charge->id,
            'invoiceId' => 'inv_1',
            'status' => 'reversed',
            'cancelList' => [
                ['amount' => 4000, 'status' => 'success'],
                ['amount' => 1000, 'status' => 'failure'], // never moved
            ],
        ]]));

        $this->assertSame('refunded', $result->status);
        $this->assertSame(4000, $charge->refundedAmount());
    }

    public function test_a_liqpay_reversal_is_recorded_from_its_refund_amount(): void
    {
        config([
            'billing.gateways.liqpay.public_key' => 'pub',
            'billing.gateways.liqpay.private_key' => 'priv',
        ]);

        $charge = $this->paidPayment('liqpay', 'pay_1');

        $result = Billing::driver('liqpay')->handleWebhook($this->liqpayReversal($charge, '40.00'));

        $this->assertSame('refunded', $result->status);
        $this->assertSame(4000, $charge->refundedAmount()); // decimal major units on the wire

        // Re-delivered: the running total hasn't moved, so nothing is added.
        $again = Billing::driver('liqpay')->handleWebhook($this->liqpayReversal($charge, '40.00'));

        $this->assertSame('ignored', $again->status);
        $this->assertSame(4000, $charge->refundedAmount());
    }

    /** A refund can never exceed the charge, however the gateway words it. */
    public function test_a_reversal_larger_than_the_charge_is_capped(): void
    {
        $charge = $this->paidPayment('stripe', 'pi_1');

        Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 999999, 're_1'));

        $this->assertSame(10000, $charge->refundedAmount());
    }

    private function liqpayReversal(Payment $charge, string $refundAmount): BillingWebhookCall
    {
        return new BillingWebhookCall(['name' => 'liqpay', 'url' => 'https://example.test/billing/webhooks/liqpay', 'payload' => [
            'data' => base64_encode(json_encode([
                'order_id' => (string) $charge->id,
                'status' => 'reversed',
                'amount' => 100.0,
                'currency' => 'UAH',
                'refund_amount' => $refundAmount,
                'payment_id' => 'pay_1',
            ])),
        ]]);
    }

    /**
     * Stripe's real charge.refunded, which carries the Charge and NO `refunds` list — live-verified
     * against a test account, where the key is simply absent. Two partial refunds therefore look
     * identical apart from `amount_refunded`.
     */
    public function test_stripes_real_payload_records_both_partials_and_keeps_the_charge_reference(): void
    {
        $charge = $this->paidPayment('stripe', 'pi_1');

        Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 2500));
        Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 3800));

        $this->assertSame(3800, $charge->refundedAmount());
        $this->assertSame([2500, 1300], $charge->refunds()->orderBy('created_at')->pluck('amount')->all());

        // The Charge id is stored so a support lookup lands somewhere, but it never dedups —
        // both rows carry it, and deduping on it would have dropped the second refund.
        $this->assertSame(['ch_1', 'ch_1'], $charge->refunds()->pluck('external_id')->all());

        // A re-delivery of the latest total adds nothing.
        $this->assertSame('ignored', Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 3800))->status);
        $this->assertSame(3800, $charge->refundedAmount());
    }

    private function stripeRefund(Payment $charge, int $cumulative, ?string $refundId = null): BillingWebhookCall
    {
        $object = [
            'id' => 'ch_1',
            'amount_refunded' => $cumulative,
            'metadata' => ['payment_id' => (string) $charge->id],
        ];

        if ($refundId !== null) {
            $object['refunds'] = ['data' => [['id' => $refundId]]];
        }

        return new BillingWebhookCall(['name' => 'stripe', 'url' => 'https://example.test/billing/webhooks/stripe', 'payload' => [
            'type' => 'charge.refunded',
            'data' => ['object' => $object],
        ]]);
    }

    private function paidPayment(string $gateway, string $externalId): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'paid',
            'type' => 'charge',
            'gateway' => $gateway,
            'amount' => 10000,
            'currency' => 'UAH',
            'external_id' => $externalId,
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
