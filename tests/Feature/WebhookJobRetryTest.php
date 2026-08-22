<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Jobs\ProcessWebhookJob;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * The dedup claim and the events it guards have to commit together. A listener throwing after the
 * claim was stamped used to leave the outcome claimed but never dispatched — the retry would see
 * its own key already on the row, call the delivery a duplicate and drop it, leaving a paid payment
 * whose PaymentSucceeded never fired.
 */
class WebhookJobRetryTest extends TestCase
{
    public function test_a_throwing_listener_rolls_the_dedup_claim_back_so_the_retry_redispatches(): void
    {
        $dispatched = 0;
        $shouldThrow = true;

        Event::listen(PaymentSucceeded::class, function () use (&$dispatched, &$shouldThrow) {
            $dispatched++;

            if ($shouldThrow) {
                throw new RuntimeException('consumer listener blew up');
            }
        });

        $payment = $this->pendingPayment();
        $call = $this->webhookCall($payment);

        try {
            (new ProcessWebhookJob($call))->handle();
            $this->fail('the listener exception should have surfaced to the worker');
        } catch (RuntimeException) {
            // the worker's cue to retry
        }

        // The Payment write lives outside the transaction (handleWebhook may call the gateway's
        // API), so it stays — but the claim must be gone for the retry to work.
        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertNull($call->fresh()->external_id);

        $shouldThrow = false;

        (new ProcessWebhookJob($call->fresh()))->handle();

        $this->assertSame(2, $dispatched, 'the retry must re-dispatch the outcome');
        $this->assertSame("Payment:succeeded:fake-{$payment->id}", $call->fresh()->external_id);
    }

    public function test_a_second_delivery_of_the_same_outcome_still_does_not_dispatch_twice(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $payment = $this->pendingPayment();

        (new ProcessWebhookJob($this->webhookCall($payment)))->handle();
        (new ProcessWebhookJob($this->webhookCall($payment)))->handle();

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_a_failed_job_records_the_exception_on_the_webhook_call(): void
    {
        $call = $this->webhookCall($this->pendingPayment());

        (new ProcessWebhookJob($call))->failed(new RuntimeException('boom'));

        $this->assertSame('boom', $call->fresh()->exception['message']);
    }

    private function webhookCall(Payment $payment): BillingWebhookCall
    {
        return BillingWebhookCall::create([
            'name' => 'fake',
            'url' => 'https://example.test/billing/webhooks/fake',
            'payload' => ['payment_id' => (string) $payment->id, 'result' => 'success'],
        ]);
    }

    private function pendingPayment(): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 5000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
