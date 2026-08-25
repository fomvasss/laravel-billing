<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentType;
use Fomvasss\Billing\Events\PaymentRefunded;
use Fomvasss\Billing\Exceptions\BillingException;
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
 * Hutko's reversal endpoint (docs.hutko.org/uk/docs/page/7) — the same host-to-host request shape
 * as the rest of the driver, with two ways of refusing that both come back as HTTP 200.
 */
class HutkoRefundTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.hutko.merchant_id', '1700002');
        $app['config']->set('billing.gateways.hutko.secret_key', 'secret_test');
    }

    public function test_a_full_refund_reverses_the_whole_amount(): void
    {
        Event::fake([PaymentRefunded::class]);
        $this->fakeReverse(['response_status' => 'success', 'reverse_status' => 'approved']);

        $charge = $this->paidPayment();

        $refund = Billing::refund($charge);

        $this->assertSame(PaymentType::Refund, $refund->type);
        $this->assertSame(10000, $charge->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);

        Http::assertSent(fn ($request) => $request->url() === 'https://pay.hutko.org/api/reverse/order_id'
            && $request['request']['order_id'] === (string) $charge->id
            && $request['request']['amount'] === 10000 // minor units, like every Hutko amount
            && $request['request']['currency'] === 'UAH'
            && $request['request']['merchant_id'] === '1700002'
            && $request['request']['signature'] !== '');
    }

    public function test_a_partial_refund_sends_its_own_figure(): void
    {
        $this->fakeReverse(['response_status' => 'success', 'reverse_status' => 'approved']);

        $charge = $this->paidPayment();

        Billing::refund($charge, new Money(2500, 'UAH'));

        $this->assertSame(2500, $charge->refundedAmount());
        $this->assertSame(7500, $charge->refundableRemainder());
        Http::assertSent(fn ($request) => $request['request']['amount'] === 2500);
    }

    /**
     * Neither field is in the documented response list, but a live reversal came back carrying
     * transaction_id (its own, distinct from the charge's) — worth storing over the charge's
     * reference, which the parent row already holds.
     */
    public function test_the_refund_row_keeps_the_reversals_own_reference(): void
    {
        $this->fakeReverse([
            'response_status' => 'success',
            'reverse_status' => 'approved',
            'reverse_id' => '',
            'transaction_id' => '503741188',
        ]);

        $refund = Billing::refund($this->paidPayment());

        $this->assertSame('503741188', $refund->external_id);
    }

    /** The acquirer refusing the reversal — the request itself was fine, so only reverse_status says so. */
    public function test_a_declined_reversal_throws_and_records_nothing(): void
    {
        $this->fakeReverse(['response_status' => 'success', 'reverse_status' => 'declined', 'response_code' => '1017']);

        $charge = $this->paidPayment();

        try {
            Billing::refund($charge);
            $this->fail('A declined reversal should throw.');
        } catch (BillingException) {
            //
        }

        $this->assertSame(0, $charge->refundedAmount());
    }

    /** ...and the other refusal: the request never validated (unknown order, missing parameter). */
    public function test_a_rejected_request_throws_and_records_nothing(): void
    {
        $this->fakeReverse(['response_status' => 'failure', 'error_message' => 'Order Not Found', 'error_code' => '1018']);

        $charge = $this->paidPayment();

        try {
            Billing::refund($charge);
            $this->fail('A rejected reverse request should throw.');
        } catch (BillingException) {
            //
        }

        $this->assertSame(0, $charge->refundedAmount());
    }

    /**
     * Hutko echoes our own reversal back as a callback carrying the order's running total. It has
     * to settle against the row Billing::refund() already wrote, not stack a second one on top.
     */
    public function test_the_callback_echoing_our_own_refund_adds_no_second_row(): void
    {
        Event::fake([PaymentRefunded::class]);
        $this->fakeReverse(['response_status' => 'success', 'reverse_status' => 'approved']);

        $charge = $this->paidPayment();

        Billing::refund($charge, new Money(4000, 'UAH'));

        $call = new BillingWebhookCall(['name' => 'hutko', 'url' => 'https://example.test/billing/webhooks/hutko', 'payload' => [
            'order_id' => (string) $charge->id,
            'payment_id' => 555,
            'order_status' => 'reversed',
            'reversal_amount' => 4000,
            'amount' => 10000,
            'currency' => 'UAH',
        ]]);
        $call->save();
        (new ProcessWebhookJob($call))->handle();

        $this->assertSame(1, $charge->refunds()->count());
        $this->assertSame(4000, $charge->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    private function fakeReverse(array $response): void
    {
        Http::fake(['https://pay.hutko.org/api/reverse/order_id' => Http::response(['response' => $response])]);
    }

    private function paidPayment(): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'paid',
            'type' => 'charge',
            'gateway' => 'hutko',
            'amount' => 10000,
            'currency' => 'UAH',
            'external_id' => 'pay_1',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
