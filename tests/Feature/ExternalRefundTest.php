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
        Event::fake([PaymentRefunded::class]);
        $charge = $this->paidPayment('stripe', 'pi_1');

        $this->process($this->stripeRefund($charge, 10000, 're_1'));
        $this->process($this->stripeRefund($charge, 10000, 're_1'));

        $this->assertSame(1, $charge->refunds()->count());
        $this->assertSame(10000, $charge->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    /**
     * The refund row is written by the driver, outside the job's transaction; a listener failing
     * after that must not turn the retry into "already recorded, nothing to dispatch" — the event
     * would be lost for good, with the money already gone.
     */
    public function test_a_retried_job_still_dispatches_the_refund_its_first_attempt_recorded(): void
    {
        $charge = $this->paidPayment('stripe', 'pi_1');
        $call = $this->stripeRefund($charge, 4000);
        $call->save();

        $attempts = 0;
        Event::listen(PaymentRefunded::class, function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new \RuntimeException('listener blew up');
            }
        });

        try {
            (new ProcessWebhookJob($call))->handle();
        } catch (\RuntimeException) {
        }

        (new ProcessWebhookJob($call))->handle();

        $this->assertSame(2, $attempts);
        $this->assertSame(1, $charge->refunds()->count());
        $this->assertSame(4000, $charge->refundedAmount());
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

        Event::fake([PaymentRefunded::class]);

        $charge = $this->paidPayment('stripe', 'pi_1');
        Billing::refund($charge, new Money(10000, 'UAH'));

        $this->process($this->stripeRefund($charge, 10000, 're_1'));

        $this->assertSame(1, $charge->refunds()->count());
        Event::assertDispatchedTimes(PaymentRefunded::class, 1); // from refund() itself — the echo adds nothing
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

        Event::fake([PaymentRefunded::class]);

        $this->process($this->liqpayReversal($charge, '40.00'));

        $this->assertSame(4000, $charge->refundedAmount()); // decimal major units on the wire

        // Re-delivered: the running total hasn't moved, so nothing is added or dispatched.
        $this->process($this->liqpayReversal($charge, '40.00'));

        $this->assertSame(1, $charge->refunds()->count());
        $this->assertSame(4000, $charge->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    /**
     * WayForPay reports each reversal's own amount, not a running total — so two partials add up,
     * and only the dedup key stops a re-delivery. Payload shape taken from a live test merchant.
     */
    public function test_wayforpay_reversals_add_up_and_a_redelivery_does_not(): void
    {
        config([
            'billing.gateways.wayforpay.merchant_account' => 'test_merch_n1',
            'billing.gateways.wayforpay.merchant_domain' => 'example.test',
            'billing.gateways.wayforpay.secret_key' => 'secret',
        ]);

        $charge = $this->paidPayment('wayforpay', 'ord_1');

        Event::fake([PaymentRefunded::class]);

        $this->process($this->wayforpayReversal($charge, 40.0, 1787421259));
        $this->process($this->wayforpayReversal($charge, 25.0, 1787421260));

        $this->assertSame(6500, $charge->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);

        // Same reversal delivered again — identical processingDate, so it is the same event.
        $this->process($this->wayforpayReversal($charge, 40.0, 1787421259));

        $this->assertSame(2, $charge->refunds()->count());
        $this->assertSame(6500, $charge->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
    }

    /**
     * Hutko announces a reversal by re-sending the ORDINARY purchase callback — still
     * `tran_type: purchase` / `order_status: approved` — with the order's running `reversal_amount`
     * filled in (payload shape taken from a live test merchant). Its `payment_id` names the order,
     * not the reversal, so two partial reversals share it: only the running total can tell them
     * apart, and deduping on that reference would silently drop the second one.
     */
    public function test_hutko_partial_reversals_settle_by_running_total(): void
    {
        config([
            'billing.gateways.hutko.merchant_id' => '1700002',
            'billing.gateways.hutko.secret_key' => 'secret',
        ]);

        $charge = $this->paidPayment('hutko', '104210513');

        Event::fake([PaymentRefunded::class]);

        $this->process($this->hutkoReversal($charge, '3000'));
        $this->process($this->hutkoReversal($charge, '5000'));

        $this->assertSame([3000, 2000], $charge->refunds()->orderBy('created_at')->pluck('amount')->all());
        $this->assertSame(5000, $charge->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);

        // Re-delivered: the running total hasn't moved, so nothing is added or dispatched.
        $this->process($this->hutkoReversal($charge, '5000'));

        $this->assertSame(2, $charge->refunds()->count());
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
    }

    /** A refund can never exceed the charge, however the gateway words it. */
    public function test_a_reversal_larger_than_the_charge_is_capped(): void
    {
        $charge = $this->paidPayment('stripe', 'pi_1');

        Billing::driver('stripe')->handleWebhook($this->stripeRefund($charge, 999999, 're_1'));

        $this->assertSame(10000, $charge->refundedAmount());
    }

    private function wayforpayReversal(Payment $charge, float $amount, int $processingDate): BillingWebhookCall
    {
        return new BillingWebhookCall(['name' => 'wayforpay', 'url' => 'https://example.test/billing/webhooks/wayforpay', 'payload' => [
            'orderReference' => (string) $charge->id,
            'transactionStatus' => 'Refunded',
            'amount' => $amount,
            'currency' => 'UAH',
            'reasonCode' => 1100,
            'processingDate' => $processingDate,
        ]]);
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
        Event::fake([PaymentRefunded::class]);
        $charge = $this->paidPayment('stripe', 'pi_1');

        $this->process($this->stripeRefund($charge, 2500));
        $this->process($this->stripeRefund($charge, 3800));

        $this->assertSame(3800, $charge->refundedAmount());
        $this->assertSame([2500, 1300], $charge->refunds()->orderBy('created_at')->pluck('amount')->all());

        // The Charge id is stored so a support lookup lands somewhere, but it never dedups —
        // both rows carry it, and deduping on it (row or webhook claim) would have dropped the
        // second refund or its event.
        $this->assertSame(['ch_1', 'ch_1'], $charge->refunds()->pluck('external_id')->all());
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);

        // A re-delivery of the latest total adds nothing.
        $this->process($this->stripeRefund($charge, 3800));
        $this->assertSame(2, $charge->refunds()->count());
        $this->assertSame(3800, $charge->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
    }

    /** The whole pipeline — driver, dedup claim, events — the way a real delivery runs. */
    private function process(BillingWebhookCall $call): void
    {
        $call->save();
        (new ProcessWebhookJob($call))->handle();
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

    private function hutkoReversal(Payment $charge, string $cumulative): BillingWebhookCall
    {
        return new BillingWebhookCall(['name' => 'hutko', 'url' => 'https://example.test/billing/webhooks/hutko', 'payload' => [
            'order_id' => (string) $charge->id,
            'payment_id' => 104210513,
            'tran_type' => 'purchase',
            'order_status' => 'approved',
            'amount' => '10000',
            'currency' => 'UAH',
            'reversal_amount' => $cumulative,
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
