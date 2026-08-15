<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * The dedup key is type+status+externalId (WebhookResult::dedupKey()), not the bare gateway
 * reference: on gateways that reuse one reference across attempts (WayForPay/Hutko order id,
 * Stripe's PaymentIntent inside a Checkout Session), "declined, then the customer retries and
 * pays" produces two DIFFERENT outcomes for the same reference — the success must not be eaten
 * by the earlier failure's claim.
 */
class WebhookDedupTest extends TestCase
{
    public function test_a_failed_then_succeeded_outcome_for_the_same_reference_both_dispatch(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        // The fake gateway uses the same externalId ("fake-{id}") for every outcome of a payment —
        // exactly the reference-reuse shape under test.
        $payment = $this->pendingPayment();

        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), [
            'payment_id' => $payment->id,
            'result' => 'failure',
        ])->assertOk();

        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), [
            'payment_id' => $payment->id,
            'result' => 'success',
        ])->assertOk();

        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        $this->assertSame('paid', $payment->fresh()->status->value);
    }

    public function test_a_webhook_for_an_unknown_payment_is_ignored_not_a_failed_job(): void
    {
        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), [
            'payment_id' => '0198c0de-0000-7000-8000-000000000000',
            'result' => 'success',
        ])->assertOk();
    }

    private function pendingPayment(): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 5000,
            'currency_code' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
