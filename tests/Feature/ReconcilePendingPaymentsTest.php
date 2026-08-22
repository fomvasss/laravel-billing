<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class ReconcilePendingPaymentsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
        $app['config']->set('billing.gateways.stripe.webhook_secret', 'whsec_test');
    }

    public function test_a_pending_off_session_payment_is_reconciled_via_its_payment_intent(): void
    {
        Event::fake([PaymentSucceeded::class]);

        // external_id is a PaymentIntent (chargePaymentMethod flow) — the sessions endpoint would 404.
        $payment = $this->stalePendingPayment('pi_123');

        Http::fake([
            'https://api.stripe.com/v1/payment_intents/pi_123' => Http::response(['id' => 'pi_123', 'status' => 'succeeded']),
        ]);

        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_a_late_webhook_after_reconciliation_does_not_dispatch_twice(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $payment = $this->stalePendingPayment('pi_123');

        Http::fake([
            'https://api.stripe.com/v1/payment_intents/pi_123' => Http::response(['id' => 'pi_123', 'status' => 'succeeded']),
        ]);

        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        // The real webhook shows up late — same outcome, same dedup key as the reconcile claim.
        $body = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'pi_123',
            'amount' => 10000,
            'currency' => 'uah',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test');

        $this->call('POST', route('billing.webhook', ['gateway' => 'stripe']), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"], $body)
            ->assertOk();

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_one_gateway_error_does_not_strand_other_pending_payments(): void
    {
        $failing = $this->stalePendingPayment('cs_broken');
        $healthy = $this->stalePendingPayment('cs_ok');

        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions/cs_broken' => Http::response(['error' => 'boom'], 500),
            'https://api.stripe.com/v1/checkout/sessions/cs_ok' => Http::response([
                'id' => 'cs_ok', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_ok',
            ]),
        ]);

        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $failing->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $healthy->fresh()->status);
    }

    public function test_a_gateway_without_status_polling_cancels_a_stale_pending_payment(): void
    {
        $payment = $this->stalePendingPayment(null, gateway: 'fake');

        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Canceled, $payment->fresh()->status);
    }

    /**
     * The webhook path refuses a "paid" callback whose sum doesn't match the row — polling has to
     * refuse the same evidence, or reconciliation quietly marks paid an hour later exactly what the
     * webhook just rejected.
     */
    public function test_polling_refuses_a_paid_status_whose_amount_does_not_match(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $payment = $this->stalePendingPayment('pi_123');

        Http::fake([
            'https://api.stripe.com/v1/payment_intents/pi_123' => Http::response([
                'id' => 'pi_123',
                'status' => 'succeeded',
                'amount' => 5000, // the row says 10000
                'currency' => 'uah',
            ]),
        ]);

        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    private function stalePendingPayment(?string $externalId, string $gateway = 'stripe'): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => $gateway,
            'amount' => 10000,
            'currency' => 'UAH',
            'external_id' => $externalId,
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'created_at' => now()->subHours(2),
        ]);
    }
}
