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
        $app['config']->set('billing.gateways.monobank.token', 'test-token');
        $app['config']->set('billing.gateways.liqpay.public_key', 'pub_test');
        $app['config']->set('billing.gateways.liqpay.private_key', 'priv_test');
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'test_merchant');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'secret_test');
        $app['config']->set('billing.gateways.hutko.merchant_id', '1');
        $app['config']->set('billing.gateways.hutko.secret_key', 'secret_test');
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

    public function test_a_due_monobank_subscription_with_a_saved_card_gets_charged(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response(['invoiceId' => 'inv_999', 'status' => 'success']),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => 'monobank',
            'currency_code' => 'UAH',
            'amount' => 10000,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        $subscription = Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => 'monobank',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->subDay(),
        ]);

        PaymentMethod::create([
            'gateway' => 'monobank',
            'external_customer_id' => 'wallet_999',
            'external_id' => 'card_tok_999',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        $payment = Payment::where('payable_type', Subscription::class)->where('payable_id', $subscription->id)->firstOrFail();
        $this->assertSame(10000, $payment->amount);
        $this->assertSame('monobank', $payment->gateway);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.monobank.ua/api/merchant/wallet/payment'
            && $request['cardToken'] === 'card_tok_999'
            && $request['initiationKind'] === 'merchant');
    }

    public function test_a_due_liqpay_subscription_with_a_saved_card_gets_charged(): void
    {
        Http::fake([
            'https://www.liqpay.ua/api/request' => Http::response(['status' => 'success', 'payment_id' => 999]),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => 'liqpay',
            'currency_code' => 'UAH',
            'amount' => 10000,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        $subscription = Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => 'liqpay',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->subDay(),
        ]);

        PaymentMethod::create([
            'gateway' => 'liqpay',
            'external_customer_id' => 'cust_999',
            'external_id' => 'card_tok_999',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        $payment = Payment::where('payable_type', Subscription::class)->where('payable_id', $subscription->id)->firstOrFail();
        $this->assertSame(10000, $payment->amount);
        $this->assertSame('liqpay', $payment->gateway);

        Http::assertSent(function ($request) {
            $decoded = json_decode(base64_decode($request['data']), true);

            return $decoded['action'] === 'paytoken' && $decoded['card_token'] === 'card_tok_999';
        });
    }

    public function test_a_due_wayforpay_subscription_with_a_saved_card_gets_charged(): void
    {
        Http::fake([
            'https://api.wayforpay.com/api' => Http::response(['orderReference' => 'x', 'transactionStatus' => 'Approved']),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => 'wayforpay',
            'currency_code' => 'UAH',
            'amount' => 10000,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        $subscription = Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => 'wayforpay',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->subDay(),
        ]);

        PaymentMethod::create([
            'gateway' => 'wayforpay',
            'external_customer_id' => 'cust_999',
            'external_id' => 'rec_tok_999',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        $payment = Payment::where('payable_type', Subscription::class)->where('payable_id', $subscription->id)->firstOrFail();
        $this->assertSame(10000, $payment->amount);
        $this->assertSame('wayforpay', $payment->gateway);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.wayforpay.com/api'
            && $request['transactionType'] === 'CHARGE'
            && $request['recToken'] === 'rec_tok_999');
    }

    public function test_a_due_hutko_subscription_with_a_saved_card_gets_charged(): void
    {
        Http::fake([
            'https://pay.hutko.org/api/recurring' => Http::response(['response' => ['order_status' => 'approved', 'payment_id' => 999]]),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => 'hutko',
            'currency_code' => 'UAH',
            'amount' => 10000,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        $subscription = Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => 'hutko',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->subDay(),
        ]);

        PaymentMethod::create([
            'gateway' => 'hutko',
            'external_customer_id' => 'cust_999',
            'external_id' => 'rec_tok_999',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        $payment = Payment::where('payable_type', Subscription::class)->where('payable_id', $subscription->id)->firstOrFail();
        $this->assertSame(10000, $payment->amount);
        $this->assertSame('hutko', $payment->gateway);

        Http::assertSent(fn ($request) => $request->url() === 'https://pay.hutko.org/api/recurring'
            && $request['request']['rectoken'] === 'rec_tok_999');
    }
}
