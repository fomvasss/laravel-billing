<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentType;
use Fomvasss\Billing\Events\PaymentRefunded;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Exceptions\NotSupportedException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Support\Money;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Billing::refund() is the orchestration half of RefundsPayments — the driver only makes the API
 * call, the manager creates the child Payment row and dispatches PaymentRefunded, so
 * refundedAmount() and the event actually reflect what happened.
 */
class RefundTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'test_merchant');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'secret_test');
    }

    public function test_a_full_refund_creates_a_child_payment_row_and_fires_the_event(): void
    {
        Event::fake([PaymentRefunded::class]);
        Http::fake(['https://api.stripe.com/v1/refunds' => Http::response(['id' => 're_1'])]);

        $charge = $this->paidStripePayment();

        $refund = Billing::refund($charge);

        $this->assertSame(PaymentType::Refund, $refund->type);
        $this->assertSame(10000, $refund->amount);
        $this->assertSame($charge->id, $refund->parent_payment_id);
        $this->assertSame(10000, $charge->refundedAmount());
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Http::assertSent(fn ($request) => $request['payment_intent'] === 'pi_1' && (int) $request['amount'] === 10000);
    }

    public function test_cumulative_refunds_cannot_exceed_the_original_amount(): void
    {
        Http::fake(['https://api.stripe.com/v1/refunds' => Http::response(['id' => 're_1'])]);

        $charge = $this->paidStripePayment();

        Billing::refund($charge, new Money(7000, 'USD'));

        $this->expectException(BillingException::class);

        Billing::refund($charge, new Money(4000, 'USD'));
    }

    public function test_only_a_paid_charge_can_be_refunded(): void
    {
        $pending = $this->paidStripePayment(['status' => 'pending']);

        $this->expectException(BillingException::class);

        Billing::refund($pending);
    }

    public function test_a_gateway_without_refund_support_throws(): void
    {
        $charge = $this->paidStripePayment(['gateway' => 'wayforpay']);

        $this->expectException(NotSupportedException::class);

        Billing::refund($charge);
    }

    private function paidStripePayment(array $attributes = []): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'paid',
            'type' => 'charge',
            'gateway' => 'stripe',
            'amount' => 10000,
            'currency' => 'USD',
            'external_id' => 'pi_1',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
