<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Event;

/**
 * Paid is terminal against gateway-driven transitions: deliveries are neither ordered nor unique
 * (Stripe explicitly doesn't guarantee order and retries for days), so an earlier decline or a
 * re-issued reference's stale "expired" can land after the success. Reverting the row would clear
 * paid_at, hand PaymentFailed to the dunning listener and make an already-paid charge unrefundable.
 */
class PaymentStatusTransitionTest extends TestCase
{
    public function test_a_late_failure_webhook_does_not_revert_a_paid_payment(): void
    {
        Event::fake([PaymentFailed::class]);

        $payment = $this->pendingPayment();

        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), [
            'payment_id' => $payment->id,
            'result' => 'success',
        ])->assertOk();

        $paidAt = $payment->fresh()->paid_at;

        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), [
            'payment_id' => $payment->id,
            'result' => 'failure',
        ])->assertOk();

        $payment->refresh();

        $this->assertSame('paid', $payment->status->value);
        $this->assertEquals($paidAt, $payment->paid_at);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** The stale-reference shape: the old checkout expires long after a re-issued one was paid. */
    public function test_a_stale_expiry_webhook_does_not_cancel_a_paid_payment(): void
    {
        $payment = $this->pendingPayment(['status' => 'paid', 'external_id' => 'pi_new']);

        $result = Billing::driver('stripe')->handleWebhook(new BillingWebhookCall(['name' => 'stripe', 'payload' => [
            'type' => 'checkout.session.expired',
            'data' => ['object' => ['id' => 'cs_old', 'metadata' => ['payment_id' => (string) $payment->id]]],
        ]]));

        $payment->refresh();

        $this->assertSame(WebhookEventType::Ignored, $result->type);
        $this->assertSame('paid', $payment->status->value);
        $this->assertSame('pi_new', $payment->external_id);
    }

    /** The other direction stays open: a failed checkout re-issued through billing.pay and then paid. */
    public function test_a_failed_payment_can_still_become_paid(): void
    {
        $payment = $this->pendingPayment(['status' => 'failed']);

        $this->assertTrue($payment->transitionTo(PaymentStatus::Paid));
        $this->assertSame('paid', $payment->fresh()->status->value);
    }

    public function test_a_duplicate_success_delivery_keeps_the_original_paid_at(): void
    {
        $payment = $this->pendingPayment();

        $payment->transitionTo(PaymentStatus::Paid);
        $paidAt = $payment->fresh()->paid_at;

        $this->travel(5)->minutes();

        $this->assertTrue($payment->fresh()->transitionTo(PaymentStatus::Paid));
        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
    }

    public function test_a_rejected_transition_writes_nothing(): void
    {
        $payment = $this->pendingPayment(['status' => 'paid', 'external_id' => 'pi_1']);

        $this->assertFalse($payment->transitionTo(PaymentStatus::Failed, ['external_id' => 'pi_2']));

        $payment->refresh();

        $this->assertSame('paid', $payment->status->value);
        $this->assertSame('pi_1', $payment->external_id);
    }

    private function pendingPayment(array $attributes = []): Payment
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
            ...$attributes,
        ]);
    }
}
