<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Events\PaymentLinkOpened;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The remaining ways one payment could be charged twice, or charged for the wrong amount, without
 * anything failing loudly.
 */
class ConcurrentChargeGuardsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_a_concurrent_pay_link_visit_does_not_issue_a_second_checkout(): void
    {
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_2',
            'url' => 'https://checkout.stripe.com/cs_2',
        ])]);

        $payment = $this->payment(['status' => 'failed']);

        // Stands in for the request that got the lock first and issued the checkout while this one
        // was waiting on it: by the time the waiter runs, the row already carries a usable link,
        // and it must re-read rather than issue a competing second session.
        Event::listen(PaymentLinkOpened::class, function () use ($payment) {
            Payment::query()->whereKey($payment->id)->update([
                'status' => PaymentStatus::Pending,
                'payment_url' => 'https://checkout.stripe.com/cs_1',
                'payment_url_expires_at' => now()->addHour(),
            ]);
        });

        $this->get(route('billing.pay', $payment))->assertRedirect('https://checkout.stripe.com/cs_1');

        Http::assertNothingSent();
    }

    public function test_a_receipt_basket_that_does_not_add_up_refuses_the_charge(): void
    {
        Http::fake();

        $payment = $this->payment();

        $this->expectException(BillingException::class);

        Billing::charge($payment, new ChargeOptions(receiptItems: [
            ['name' => 'Widget', 'qty' => 2, 'unitAmount' => 1000], // 2000, the payment is 5000
        ]));
    }

    public function test_an_off_session_charge_refuses_a_basket_that_does_not_add_up(): void
    {
        Http::fake();

        $payment = $this->payment();
        $method = PaymentMethod::create([
            'gateway' => 'stripe',
            'external_customer_id' => 'cus_1',
            'external_id' => 'pm_1',
            'is_default' => true,
            'billable_type' => $payment->billable_type,
            'billable_id' => $payment->billable_id,
        ]);

        $this->expectException(BillingException::class);

        Billing::chargeWithMethod($payment, $method, new ChargeOptions(receiptItems: [
            ['name' => 'Widget', 'qty' => 1, 'unitAmount' => 4999],
        ]));
    }

    /** The customer's first card was declined inside a Checkout Session that is still open. */
    public function test_a_declined_attempt_inside_a_live_checkout_does_not_fail_the_payment(): void
    {
        $payment = $this->payment(['external_id' => 'cs_1', 'payment_url' => 'https://checkout.stripe.com/cs_1']);

        $result = Billing::driver('stripe')->handleWebhook(new BillingWebhookCall(['name' => 'stripe', 'payload' => [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => 'pi_1', 'metadata' => ['payment_id' => (string) $payment->id]]],
        ]]));

        $this->assertSame(WebhookEventType::Ignored, $result->type);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    /** An off-session charge has no page to retry on — its decline is terminal. */
    public function test_a_declined_off_session_intent_still_fails_the_payment(): void
    {
        $payment = $this->payment(['external_id' => 'pi_1']);

        $result = Billing::driver('stripe')->handleWebhook(new BillingWebhookCall(['name' => 'stripe', 'payload' => [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => 'pi_1', 'metadata' => ['payment_id' => (string) $payment->id]]],
        ]]));

        $this->assertSame(WebhookEventType::Payment, $result->type);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
    }

    private function payment(array $attributes = []): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'stripe',
            'amount' => 5000,
            'currency' => 'USD',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
