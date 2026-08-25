<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Contracts\RenewalChargeOptionsContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Enums\PaymentStatus;
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
 * A scheduled renewal is the one charge nobody outside the package builds ChargeOptions for — no
 * request, and a Payable (the Subscription row) that can't carry receiptItems. These cover the
 * hook that closes that gap: fiscalizing a renewal the way the first payment was fiscalized.
 */
class RenewalChargeOptionsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.monobank.token', 'test-token');
        $app['config']->set('billing.gateways.hutko.merchant_id', '1');
        $app['config']->set('billing.gateways.hutko.secret_key', 'secret_test');
    }

    public function test_a_renewal_carries_no_basket_by_default(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response(['invoiceId' => 'inv_1']),
        ]);

        $this->makeDueSubscription('monobank');

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        Http::assertSent(fn ($request) => ! isset($request['merchantPaymInfo']['basketOrder']));
    }

    public function test_the_config_flag_puts_a_one_line_basket_on_every_renewal(): void
    {
        config()->set('billing.renewal.receipt_items', true);

        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response(['invoiceId' => 'inv_1']),
        ]);

        $this->makeDueSubscription('monobank');

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request['merchantPaymInfo']['basketOrder'] === [
            ['name' => 'Pro', 'qty' => 1, 'sum' => 10000],
        ]);
    }

    public function test_the_price_can_name_the_basket_line_itself(): void
    {
        config()->set('billing.renewal.receipt_items', true);

        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response(['invoiceId' => 'inv_1']),
        ]);

        $subscription = $this->makeDueSubscription('monobank');
        $subscription->price->update(['meta' => ['receipt_name' => 'Послуга "Pro", 1 міс']]);

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request['merchantPaymInfo']['basketOrder'][0]['name'] === 'Послуга "Pro", 1 міс');
    }

    /**
     * The whole point of resolving ChargeOptions rather than just receipt items: a renewal has no
     * request behind it, so the description and the customer's IP (LiqPay demands one for an
     * off-session charge, Hutko sends 127.0.0.1 without it) have nowhere else to come from.
     */
    public function test_a_bound_resolver_supplies_the_basket_description_and_ip(): void
    {
        Http::fake([
            'https://pay.hutko.org/api/recurring' => Http::response(['response' => ['payment_id' => 1]]),
        ]);

        $this->app->bind(RenewalChargeOptionsContract::class, fn () => new class implements RenewalChargeOptionsContract
        {
            public function resolve(Subscription $subscription, Payment $payment): ChargeOptions
            {
                return new ChargeOptions(
                    receiptItems: [
                        ['name' => 'Абонплата', 'qty' => 1, 'unitAmount' => 6000],
                        ['name' => 'Місце користувача', 'qty' => 2, 'unitAmount' => 2000],
                    ],
                    customerIp: '203.0.113.7',
                    description: "Продовження підписки {$subscription->price->plan->code}",
                );
            }
        });

        $this->makeDueSubscription('hutko');

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $basket = json_decode(base64_decode($request['request']['reservation_data']), true);

            // == not ===: Hutko's basket carries decimal units, and 20.00 survives the JSON
            // round trip as an int — the numbers are what's under test here, not their PHP type.
            return $request['request']['client_ip'] === '203.0.113.7'
                && $request['request']['order_desc'] === 'Продовження підписки pro'
                && $basket['products'][1] == ['id' => 2, 'name' => 'Місце користувача', 'price' => 20.0, 'total_amount' => 40.0, 'quantity' => 2.0];
        });
    }

    /**
     * A basket that doesn't add up is refused before the gateway is called (Stripe would otherwise
     * bill the line items rather than the amount) — the renewal has to end up written off as
     * failed and dunned, never left pending, since a pending renewal blocks every later one.
     */
    public function test_a_resolver_whose_basket_does_not_add_up_fails_the_renewal_instead_of_stalling_it(): void
    {
        Http::fake();

        $this->app->bind(RenewalChargeOptionsContract::class, fn () => new class implements RenewalChargeOptionsContract
        {
            public function resolve(Subscription $subscription, Payment $payment): ChargeOptions
            {
                return new ChargeOptions(receiptItems: [['name' => 'Wrong', 'qty' => 1, 'unitAmount' => 1]]);
            }
        });

        $subscription = $this->makeDueSubscription('monobank');

        $this->artisan('billing:process-recurring-charges')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Failed, Payment::firstOrFail()->status);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()->status);
    }

    protected function makeDueSubscription(string $gateway): Subscription
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => $gateway,
            'currency' => 'UAH',
            'amount' => 10000,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        PaymentMethod::create([
            'gateway' => $gateway,
            'external_customer_id' => 'cust_1',
            'external_id' => 'tok_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        return Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => $gateway,
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->subDay(),
        ]);
    }
}
