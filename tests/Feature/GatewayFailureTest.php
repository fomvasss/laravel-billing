<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * Why a charge was refused is the gateway's own words, recorded on `failed` only — never guessed,
 * and gone once the same row is paid. Payload shapes mirror each gateway's documented decline
 * (WayForPay's is a live-captured production callback).
 */
class GatewayFailureTest extends TestCase
{
    public function test_wayforpay_records_reason_and_reason_code(): void
    {
        $payment = $this->pendingPayment('wayforpay');

        $this->webhook('wayforpay', [
            'orderReference' => (string) $payment->id,
            'transactionStatus' => 'Declined',
            'reason' => 'Insufficient funds',
            'reasonCode' => 1104,
            'amount' => 19.16,
            'currency' => 'UAH',
        ]);

        $payment->refresh();
        $this->assertTrue($payment->isFailed());
        $this->assertSame('1104', $payment->failure_code);
        $this->assertSame('Insufficient funds', $payment->failure_message);
        $this->assertSame('Insufficient funds (1104)', $payment->failureReason());
    }

    public function test_monobank_records_err_code_and_failure_reason(): void
    {
        $payment = $this->pendingPayment('monobank');

        $this->webhook('monobank', [
            'reference' => (string) $payment->id,
            'invoiceId' => 'inv_1',
            'status' => 'failure',
            'errCode' => '6',
            'failureReason' => 'Операцію заблоковано банком-емітентом',
            'amount' => 1916,
            'ccy' => 980,
        ]);

        $payment->refresh();
        $this->assertTrue($payment->isFailed());
        $this->assertSame('Операцію заблоковано банком-емітентом (6)', $payment->failureReason());
    }

    public function test_stripe_records_decline_code_over_code(): void
    {
        $payment = $this->pendingPayment('stripe', attributes: ['external_id' => 'pi_1']);

        $this->webhook('stripe', [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_1',
                'metadata' => ['payment_id' => (string) $payment->id],
                'last_payment_error' => [
                    'type' => 'card_error',
                    'code' => 'card_declined',
                    'decline_code' => 'insufficient_funds',
                    'message' => 'Your card has insufficient funds.',
                ],
            ]],
        ]);

        $this->assertSame('Your card has insufficient funds. (insufficient_funds)', $payment->fresh()->failureReason());
    }

    public function test_liqpay_records_err_code_and_err_description(): void
    {
        $payment = $this->pendingPayment('liqpay');

        Billing::driver('liqpay')->handleWebhook(new BillingWebhookCall(['name' => 'liqpay', 'payload' => ['data' => base64_encode(json_encode([
            'status' => 'failure',
            'order_id' => (string) $payment->id,
            'payment_id' => 555,
            'err_code' => '9859',
            'err_description' => 'Недостатньо коштів',
        ]))]]));

        $this->assertSame('Недостатньо коштів (9859)', $payment->fresh()->failureReason());
    }

    public function test_hutko_records_response_code_and_description(): void
    {
        $payment = $this->pendingPayment('hutko');

        $this->webhook('hutko', [
            'order_id' => (string) $payment->id,
            'payment_id' => 800001,
            'order_status' => 'declined',
            'response_code' => 1004,
            'response_description' => 'Do not honor',
            'amount' => '1916',
            'currency' => 'UAH',
        ]);

        $this->assertSame('Do not honor (1004)', $payment->fresh()->failureReason());
    }

    public function test_a_decline_without_a_reason_records_nothing(): void
    {
        $payment = $this->pendingPayment('monobank');

        $this->webhook('monobank', ['reference' => (string) $payment->id, 'invoiceId' => 'inv_1', 'status' => 'failure', 'failureReason' => '']);

        $payment->refresh();
        $this->assertTrue($payment->isFailed());
        $this->assertNull($payment->failure_code);
        $this->assertNull($payment->failure_message);
        $this->assertNull($payment->failureReason());
    }

    public function test_an_expired_checkout_is_not_a_refusal(): void
    {
        $payment = $this->pendingPayment('wayforpay');

        $this->webhook('wayforpay', [
            'orderReference' => (string) $payment->id,
            'transactionStatus' => 'Expired',
            'reason' => 'Expired',
            'reasonCode' => 1135,
        ]);

        $payment->refresh();
        $this->assertSame('canceled', $payment->status->value);
        $this->assertNull($payment->failure_code);
        $this->assertNull($payment->failureReason());
    }

    public function test_paying_the_same_row_after_a_decline_clears_the_reason(): void
    {
        $payment = $this->pendingPayment('wayforpay');

        $this->webhook('wayforpay', [
            'orderReference' => (string) $payment->id,
            'transactionStatus' => 'Declined',
            'reason' => 'Insufficient funds',
            'reasonCode' => 1104,
        ]);
        $this->webhook('wayforpay', [
            'orderReference' => (string) $payment->id,
            'transactionStatus' => 'Approved',
            'reason' => 'Ok',
            'reasonCode' => 1100,
            'amount' => 19.16,
            'currency' => 'UAH',
        ]);

        $payment->refresh();
        $this->assertTrue($payment->isPaid());
        $this->assertNull($payment->failure_code);
        $this->assertNull($payment->failure_message);
    }

    private function webhook(string $gateway, array $payload): void
    {
        Billing::driver($gateway)->handleWebhook(new BillingWebhookCall(['name' => $gateway, 'payload' => $payload]));
    }

    private function pendingPayment(string $gateway, int $amount = 1916, array $attributes = []): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => $gateway,
            'amount' => $amount,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
