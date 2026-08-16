<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * A signed "paid" callback whose sum doesn't match the Payment row must NOT mark it paid — the
 * classic case is a stale checkout link paid after the order's amount was edited and charge()
 * re-issued with a new sum.
 */
class AmountMismatchTest extends TestCase
{
    public function test_a_paid_webhook_with_a_different_amount_leaves_the_payment_pending(): void
    {
        $payment = $this->pendingPayment();

        $result = Billing::driver('monobank')->handleWebhook(new BillingWebhookCall(['name' => 'monobank', 'payload' => [
            'reference' => (string) $payment->id,
            'invoiceId' => 'inv_1',
            'status' => 'success',
            'amount' => 5000, // the payment says 10000
            'ccy' => 980,
        ]]));

        $this->assertSame(WebhookEventType::Ignored, $result->type);
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_a_paid_webhook_with_the_matching_amount_marks_the_payment_paid(): void
    {
        $payment = $this->pendingPayment();

        $result = Billing::driver('monobank')->handleWebhook(new BillingWebhookCall(['name' => 'monobank', 'payload' => [
            'reference' => (string) $payment->id,
            'invoiceId' => 'inv_1',
            'status' => 'success',
            'amount' => 10000,
            'ccy' => 980,
        ]]));

        $this->assertSame(WebhookEventType::Payment, $result->type);
        $this->assertSame('paid', $payment->fresh()->status->value);
    }

    private function pendingPayment(): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

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
