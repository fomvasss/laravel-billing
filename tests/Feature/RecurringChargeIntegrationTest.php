<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * End-to-end proof that a Stripe subscription with a saved card actually gets charged by
 * `billing:process-recurring-charges` — the gap Stripe's TokenizesPaymentMethod closes (see
 * "Токенізація" in the package plan): before it, this command silently skipped every subscription
 * regardless of gateway, since no built-in driver implemented the contract.
 */
class RecurringChargeIntegrationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_a_due_stripe_subscription_with_a_saved_card_gets_charged(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_999', 'status' => 'succeeded']),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => 'stripe',
            'currency_code' => 'USD',
            'amount' => 2900,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        $subscription = Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => 'stripe',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->subDay(),
        ]);

        PaymentMethod::create([
            'gateway' => 'stripe',
            'external_customer_id' => 'cus_999',
            'external_id' => 'pm_999',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        $payment = Payment::where('payable_type', Subscription::class)->where('payable_id', $subscription->id)->firstOrFail();
        $this->assertSame(2900, $payment->amount);
        $this->assertSame('stripe', $payment->gateway);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/payment_intents'
            && $request['customer'] === 'cus_999'
            && $request['payment_method'] === 'pm_999');
    }
}
