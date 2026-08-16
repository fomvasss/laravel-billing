<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * The gateway's commission is a FACT parsed from its callback — never guessed: no fee field means
 * `fee` stays null ("unknown"), a reported 0 records as "known, zero". Payload shapes below mirror
 * the live-captured webhooks from the sandbox run (minor units for Monobank/Hutko, decimal for
 * LiqPay/WayForPay, Hutko's "" for unset). Own commission policy is a consumer listener — see
 * "Gateway fee and net amount" in the README.
 */
class GatewayFeeTest extends TestCase
{
    public function test_monobank_stores_payment_info_fee_in_minor_units(): void
    {
        $payment = $this->pendingPayment('monobank', 5500);

        Billing::driver('monobank')->handleWebhook(new BillingWebhookCall(['name' => 'monobank', 'payload' => [
            'reference' => (string) $payment->id,
            'invoiceId' => 'inv_1',
            'status' => 'success',
            'amount' => 5500,
            'ccy' => 980,
            'paymentInfo' => ['fee' => 72],
        ]]));

        $payment->refresh();
        $this->assertSame(72, $payment->fee);
        $this->assertSame(5428, $payment->netAmount());
    }

    public function test_fee_stays_null_when_the_gateway_does_not_report_it(): void
    {
        $payment = $this->pendingPayment('monobank', 5500);

        // Monobank's early deliveries carry no paymentInfo block at all
        Billing::driver('monobank')->handleWebhook(new BillingWebhookCall(['name' => 'monobank', 'payload' => [
            'reference' => (string) $payment->id,
            'invoiceId' => 'inv_1',
            'status' => 'success',
            'amount' => 5500,
            'ccy' => 980,
        ]]));

        $payment->refresh();
        $this->assertTrue($payment->isPaid());
        $this->assertNull($payment->fee);
        $this->assertNull($payment->netAmount());
    }

    public function test_liqpay_converts_decimal_receiver_commission(): void
    {
        $payment = $this->pendingPayment('liqpay', 100);

        $decoded = [
            'status' => 'success',
            'order_id' => (string) $payment->id,
            'payment_id' => 555,
            'amount' => 1,
            'currency' => 'UAH',
            'receiver_commission' => 0.01,
            'sender_commission' => 0.5, // the customer's cost, not ours — must be ignored
        ];

        Billing::driver('liqpay')->handleWebhook(new BillingWebhookCall([
            'name' => 'liqpay',
            'payload' => ['data' => base64_encode(json_encode($decoded))],
        ]));

        $this->assertSame(1, $payment->fresh()->fee);
    }

    public function test_wayforpay_records_a_reported_zero_as_known_zero(): void
    {
        $payment = $this->pendingPayment('wayforpay', 100);

        Billing::driver('wayforpay')->handleWebhook(new BillingWebhookCall(['name' => 'wayforpay', 'payload' => [
            'orderReference' => (string) $payment->id,
            'transactionStatus' => 'Approved',
            'amount' => 1,
            'currency' => 'UAH',
            'fee' => 0,
        ]]));

        $payment->refresh();
        $this->assertSame(0, $payment->fee);
        $this->assertSame($payment->amount, $payment->netAmount());
    }

    public function test_hutko_ignores_empty_string_but_stores_a_numeric_fee(): void
    {
        $payment = $this->pendingPayment('hutko', 6600);

        $base = [
            'order_id' => (string) $payment->id,
            'payment_id' => 800001,
            'order_status' => 'approved',
            'amount' => '6600',
            'currency' => 'UAH',
        ];

        Billing::driver('hutko')->handleWebhook(new BillingWebhookCall([
            'name' => 'hutko',
            'payload' => $base + ['fee' => ''], // the test merchant's actual shape
        ]));
        $this->assertNull($payment->fresh()->fee);

        Billing::driver('hutko')->handleWebhook(new BillingWebhookCall([
            'name' => 'hutko',
            'payload' => $base + ['fee' => '86'],
        ]));
        $this->assertSame(86, $payment->fresh()->fee);
    }

    private function pendingPayment(string $gateway, int $amount): Payment
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
        ]);
    }
}
