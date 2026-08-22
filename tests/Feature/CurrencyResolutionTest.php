<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Contracts\CurrencyConverterContract;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Support\Money;
use Fomvasss\Billing\Tests\TestCase;

/**
 * The 4-step order from "Валюти" in the package plan. The `fake` driver's supportedCurrencies()
 * is ['UAH', 'USD', 'EUR'] — deliberately used here instead of a test double, so this exercises
 * the real driver-introspection path (BillingManager::resolveChargeAmount() reads $drivers[$gateway]
 * statically), not a mock of it.
 */
class CurrencyResolutionTest extends TestCase
{
    public function test_step_one_uses_the_price_own_currency_when_the_gateway_supports_it(): void
    {
        $price = $this->plan()->prices()->create(['currency' => 'UAH', 'amount' => 10000, 'pricing_type' => 'flat']);

        $resolved = app(BillingManager::class)->resolveChargeAmount($price, 'fake');

        $this->assertEquals(new Money(10000, 'UAH'), $resolved->money);
        $this->assertNull($resolved->convertedFromCurrency);
    }

    public function test_step_two_falls_back_to_a_sibling_price_of_the_same_plan_and_gateway(): void
    {
        $plan = $this->plan();
        // GBP isn't in the fake driver's supportedCurrencies() — the sibling UAH price should win.
        $unsupported = $plan->prices()->create(['currency' => 'GBP', 'amount' => 3000, 'gateway' => 'fake', 'pricing_type' => 'flat']);
        $sibling = $plan->prices()->create(['currency' => 'UAH', 'amount' => 12000, 'gateway' => 'fake', 'pricing_type' => 'flat']);

        $resolved = app(BillingManager::class)->resolveChargeAmount($unsupported, 'fake');

        $this->assertEquals(new Money($sibling->amount, 'UAH'), $resolved->money);
        $this->assertNull($resolved->convertedFromCurrency);
    }

    public function test_a_sibling_price_must_match_the_billing_cycle_and_pricing_model(): void
    {
        $plan = $this->plan();
        $monthly = $plan->prices()->create([
            'currency' => 'GBP', 'amount' => 3000, 'gateway' => 'fake', 'pricing_type' => 'flat',
            'interval' => 'month', 'interval_count' => 1,
        ]);
        // Same plan, accepted currency — but a yearly price. Billing the monthly subscription this
        // amount would charge a year's money for a month.
        $plan->prices()->create([
            'currency' => 'UAH', 'amount' => 120000, 'gateway' => 'fake', 'pricing_type' => 'flat',
            'interval' => 'year', 'interval_count' => 1,
        ]);

        $this->expectException(\Fomvasss\Billing\Exceptions\BillingException::class);

        app(BillingManager::class)->resolveChargeAmount($monthly, 'fake');
    }

    public function test_a_retired_sibling_price_is_not_used(): void
    {
        $plan = $this->plan();
        $unsupported = $plan->prices()->create(['currency' => 'GBP', 'amount' => 3000, 'gateway' => 'fake', 'pricing_type' => 'flat']);
        $plan->prices()->create([
            'currency' => 'UAH', 'amount' => 12000, 'gateway' => 'fake', 'pricing_type' => 'flat',
            'is_active' => false,
        ]);

        $this->expectException(\Fomvasss\Billing\Exceptions\BillingException::class);

        app(BillingManager::class)->resolveChargeAmount($unsupported, 'fake');
    }

    public function test_config_override_extends_or_narrows_a_drivers_currency_list(): void
    {
        // Extend: GBP isn't in the fake driver's list, the config override says the merchant
        // account takes it — step 1 must now accept it as-is.
        config()->set('billing.gateways.fake.currencies', ['uah', 'gbp']); // lowercase on purpose — normalized
        $this->assertSame(['UAH', 'GBP'], app(BillingManager::class)->supportedCurrencies('fake'));

        $plan = $this->plan();
        $price = $plan->prices()->create(['currency' => 'GBP', 'amount' => 3000, 'pricing_type' => 'flat']);
        $resolved = app(BillingManager::class)->resolveChargeAmount($price, 'fake');
        $this->assertEquals(new Money(3000, 'GBP'), $resolved->money);

        // Narrow: USD is in the driver's default list but not in the override — must throw now.
        // (Its GBP sibling above would normally rescue it — an unrelated plan avoids that.)
        $usd = \Fomvasss\Billing\Models\Plan::create(['code' => 'other', 'name' => 'Other'])
            ->prices()->create(['currency' => 'USD', 'amount' => 500, 'pricing_type' => 'flat']);
        $this->expectException(BillingException::class);
        app(BillingManager::class)->resolveChargeAmount($usd, 'fake');
    }

    public function test_step_three_converts_via_the_bound_currency_converter(): void
    {
        $this->app->bind(CurrencyConverterContract::class, fn () => new class implements CurrencyConverterContract {
            public function convert(Money $amount, string $toCurrency, ?\DateTimeInterface $at = null): Money
            {
                return new Money((int) round($amount->amount * 40), $toCurrency); // toy $ -> UAH rate
            }
        });

        $price = $this->plan()->prices()->create(['currency' => 'GBP', 'amount' => 100, 'pricing_type' => 'flat']);

        $resolved = app(BillingManager::class)->resolveChargeAmount($price, 'fake');

        $this->assertSame(4000, $resolved->money->amount);
        $this->assertSame('GBP', $resolved->convertedFromCurrency);
        $this->assertNotNull($resolved->exchangeRateAt);
    }

    public function test_step_four_throws_when_nothing_matches_and_no_converter_is_bound(): void
    {
        $price = $this->plan()->prices()->create(['currency' => 'GBP', 'amount' => 100, 'pricing_type' => 'flat']);

        $this->expectException(BillingException::class);

        app(BillingManager::class)->resolveChargeAmount($price, 'fake');
    }

    private function plan(): Plan
    {
        return Plan::create(['code' => 'pro', 'name' => 'Pro']);
    }
}
