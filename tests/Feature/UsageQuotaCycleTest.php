<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionQuotaReset;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * A quota cycle of its own: "pay for a year, get included_units every month". Without it the
 * allowance can only follow the billing period, so a yearly price hands out twelve months' worth
 * up front — the exact shape a yearly plan with a monthly limit needs to avoid.
 */
class UsageQuotaCycleTest extends TestCase
{
    public function test_quota_boundary_is_stamped_when_the_subscription_starts(): void
    {
        $subscription = $this->subscription();

        // Not the billing period (a year away) — the allowance renews on its own cadence
        $this->assertTrue($subscription->quota_period_ends_at->isSameDay(now()->addMonth()));
    }

    public function test_a_price_without_a_quota_cycle_is_untouched(): void
    {
        $subscription = $this->subscription(['quota_interval' => null]);

        $this->assertNull($subscription->quota_period_ends_at);

        $subscription->reportUsage(500);
        $this->artisan('billing:reset-usage-quotas')->assertSuccessful();

        $this->assertSame(500.0, $subscription->fresh()->current_usage);
    }

    public function test_due_cycle_zeroes_usage_and_moves_the_boundary(): void
    {
        Event::fake([SubscriptionQuotaReset::class]);

        $due = $this->subscription();
        $due->reportUsage(7500);
        $due->update(['quota_period_ends_at' => now()->subHour()]);

        $notYetDue = $this->subscription();
        $notYetDue->reportUsage(3000);

        $this->artisan('billing:reset-usage-quotas')->assertSuccessful();

        $this->assertSame(0.0, $due->fresh()->current_usage);
        $this->assertTrue($due->fresh()->quota_period_ends_at->isFuture());
        $this->assertSame(3000.0, $notYetDue->fresh()->current_usage, 'чужий цикл ще не закінчився');

        Event::assertDispatchedTimes(SubscriptionQuotaReset::class, 1);
    }

    /** Пропущені цикли не накопичуються: місяць без використання просто згорає. */
    public function test_missed_cycles_grant_one_allowance_not_several(): void
    {
        $subscription = $this->subscription();
        $subscription->reportUsage(9000);
        $subscription->update(['quota_period_ends_at' => now()->subMonths(3)]);

        $this->artisan('billing:reset-usage-quotas')->assertSuccessful();

        $subscription->refresh();

        $this->assertSame(0.0, $subscription->current_usage);
        $this->assertTrue($subscription->quota_period_ends_at->isFuture(), 'межа має перестрибнути всі пропущені цикли');
        $this->assertTrue($subscription->quota_period_ends_at->lessThan(now()->addMonth()->addDay()));
    }

    /** Квота — не гроші, тож перерахунок нічого не платить: підписка провайдера теж скидається. */
    public function test_provider_managed_subscriptions_are_reset_too(): void
    {
        $subscription = $this->subscription();
        $subscription->reportUsage(1000);
        $subscription->update(['external_id' => 'sub_provider_123', 'quota_period_ends_at' => now()->subHour()]);

        $this->artisan('billing:reset-usage-quotas')->assertSuccessful();

        $this->assertSame(0.0, $subscription->fresh()->current_usage);
    }

    /** Підписці на паузі квота не оновлюється — це була б тиха роздача того, за що ніхто не платить. */
    public function test_paused_subscription_keeps_its_stale_boundary(): void
    {
        $subscription = $this->subscription();
        $subscription->reportUsage(1000);
        $subscription->update(['status' => SubscriptionStatus::Paused, 'quota_period_ends_at' => now()->subHour()]);

        $this->artisan('billing:reset-usage-quotas')->assertSuccessful();

        $this->assertSame(1000.0, $subscription->fresh()->current_usage);
    }

    /** Оплачений період і так обнуляє квоту — цикл має початись від цього моменту, а не тягтись зі старої межі. */
    public function test_renewal_restarts_the_quota_cycle(): void
    {
        $subscription = $this->subscription();
        $subscription->reportUsage(5000);
        $subscription->update(['quota_period_ends_at' => now()->addDays(2)]);

        $subscription->recordRenewalSuccess('fake');
        $subscription->refresh();

        $this->assertSame(0.0, $subscription->current_usage);
        $this->assertTrue($subscription->quota_period_ends_at->greaterThan(now()->addWeeks(3)));
    }

    public function test_remaining_usage_follows_the_cycle(): void
    {
        $subscription = $this->subscription();
        $subscription->reportUsage(9500);

        $this->assertSame(500.0, $subscription->remainingUsage());

        $subscription->update(['quota_period_ends_at' => now()->subHour()]);
        $this->artisan('billing:reset-usage-quotas')->assertSuccessful();

        $this->assertSame(10000.0, $subscription->fresh()->remainingUsage());
    }

    private function subscription(array $priceOverrides = []): Subscription
    {
        $plan = Plan::create(['code' => 'ai-' . uniqid(), 'name' => 'AI']);

        $price = Price::create(array_merge([
            'plan_id' => $plan->id,
            'currency' => 'USD',
            'amount' => 10000,
            'pricing_type' => 'flat',
            'interval' => 'year',
            'interval_count' => 1,
            'included_units' => 10000,
            'quota_interval' => 'month',
            'quota_interval_count' => 1,
        ], $priceOverrides));

        $user = TestUser::create(['name' => 'Buyer']);

        return Subscription::create([
            'status' => SubscriptionStatus::Active,
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->addYear(),
        ]);
    }
}
