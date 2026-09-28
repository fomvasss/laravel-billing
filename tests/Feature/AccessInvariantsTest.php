<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

/**
 * The two invariants that keep entitlement a property of the data rather than of the crontab:
 *
 * 1. Running the scheduled commands never changes anyone's access — they materialize a status
 *    isActive() already treats as settled, and dispatch events. Turn the schedule off and access
 *    stays correct.
 * 2. isActive() (PHP) and scopeActive() (SQL) answer identically for every row.
 *
 * Both run over one fixture matrix, so a new status or a new date boundary gets covered by adding
 * a row to matrix() rather than by remembering to write two more tests.
 */
class AccessInvariantsTest extends TestCase
{
    public function test_running_the_schedulers_never_changes_who_has_access(): void
    {
        $subscriptions = $this->matrix();

        $before = $subscriptions->mapWithKeys(fn (Subscription $s) => [$s->id => $s->isActive()]);
        $providerRows = $subscriptions->filter->isProviderManaged()->map->fresh()->map->getAttributes();

        $this->artisan('billing:expire-trials')->assertSuccessful();
        $this->artisan('billing:expire-pauses')->assertSuccessful();
        $this->artisan('billing:send-period-notices')->assertSuccessful();
        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        foreach ($subscriptions as $label => $subscription) {
            $this->assertSame(
                $before[$subscription->id],
                $subscription->fresh()->isActive(),
                "Running the schedulers changed access for the [{$label}] subscription.",
            );
        }

        // Stronger for a provider-managed row: the schedulers must not change its state at all — it
        // belongs to the provider and reaches the row only through applyProviderSnapshot(). The one
        // column they may touch is the local reminder marker (trial notices for a provider that
        // sends none of its own).
        $state = fn (array $attributes) => array_diff_key($attributes, array_flip(['trial_notices_sent', 'updated_at']));

        foreach ($providerRows as $label => $attributes) {
            $this->assertSame($state($attributes), $state($subscriptions[$label]->fresh()->getAttributes()), "A scheduler changed the [{$label}] subscription.");
        }
    }

    public function test_the_php_and_sql_halves_of_the_predicate_agree(): void
    {
        $subscriptions = $this->matrix();

        $activeIds = Subscription::query()->active()->pluck('id')->all();

        foreach ($subscriptions as $label => $subscription) {
            $this->assertSame(
                $subscription->isActive(),
                in_array($subscription->id, $activeIds, true),
                "isActive() and scopeActive() disagree about the [{$label}] subscription.",
            );
        }
    }

    public function test_the_three_hard_boundaries_end_access_at_their_timestamp(): void
    {
        $matrix = $this->matrix();

        // A trial that ran out: no charge is coming, so access is over the moment it lapses —
        // billing:expire-trials only writes the status down afterwards.
        $this->assertTrue($matrix['trialing, ends tomorrow']->isActive());
        $this->assertFalse($matrix['trialing, lapsed an hour ago']->isActive());

        // A cancellation the customer scheduled themselves.
        $this->assertTrue($matrix['active, cancels tomorrow']->isActive());
        $this->assertFalse($matrix['active, cancellation due an hour ago']->isActive());

        // A pause with a resume time that has come.
        $this->assertFalse($matrix['paused indefinitely']->isActive());
        $this->assertTrue($matrix['paused, resume due an hour ago']->isActive());

        // ...and the boundary that is deliberately soft: the renewal charge is in flight.
        $this->assertTrue($matrix['active, period ended an hour ago']->isActive());
    }

    public function test_a_provider_managed_subscription_leaves_the_trial_end_and_dunning_to_the_provider(): void
    {
        $matrix = $this->matrix();

        // The provider converts the trial and tells us by webhook — a hard cut at trial_ends_at
        // would blink the customer offline until it lands.
        $this->assertTrue($matrix['provider-managed, trialing, lapsed an hour ago']->isActive());

        // No grace window of ours: access holds (grace_access default) for as long as the provider
        // keeps retrying, and ends when it reports the cancellation.
        $this->assertTrue($matrix['provider-managed, past_due, no grace window']->isActive());

        // The customer's own scheduled cancellation stays a hard boundary.
        $this->assertFalse($matrix['provider-managed, cancellation due an hour ago']->isActive());
        $this->assertFalse($matrix['provider-managed, paused indefinitely']->isActive());
    }

    /** @return \Illuminate\Support\Collection<string, Subscription> */
    private function matrix(): \Illuminate\Support\Collection
    {
        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'currency' => 'UAH',
            'amount' => 10000,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);
        $user = TestUser::create(['name' => 'Buyer']);

        $rows = [
            'incomplete, waiting for the first payment' => ['status' => SubscriptionStatus::Incomplete],
            'trialing, ends tomorrow' => ['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDay()],
            'trialing, lapsed an hour ago' => ['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->subHour()],
            'trialing, open-ended' => ['status' => SubscriptionStatus::Trialing],
            'active, period ends tomorrow' => ['status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addDay()],
            'active, period ended an hour ago' => ['status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->subHour()],
            'active, cancels tomorrow' => ['status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addDay(), 'cancels_at' => now()->addDay()],
            'active, cancellation due an hour ago' => ['status' => SubscriptionStatus::Active, 'cancels_at' => now()->subHour()],
            'past_due, inside grace' => ['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->addDay(), 'recurring_attempts' => 1, 'next_retry_at' => now()->addDay()],
            'past_due, grace expired' => ['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->subHour(), 'recurring_attempts' => 1, 'next_retry_at' => now()->addDay()],
            'paused indefinitely' => ['status' => SubscriptionStatus::Paused],
            'paused, resume due an hour ago' => ['status' => SubscriptionStatus::Paused, 'pause_ends_at' => now()->subHour()],
            'paused, resume due tomorrow' => ['status' => SubscriptionStatus::Paused, 'pause_ends_at' => now()->addDay()],
            'canceled' => ['status' => SubscriptionStatus::Canceled],
            'ended' => ['status' => SubscriptionStatus::Ended],
            'provider-managed, trialing, ends tomorrow' => ['gateway' => 'stripe', 'external_id' => 'sub_trial_soon', 'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDay()],
            'provider-managed, trialing, lapsed an hour ago' => ['gateway' => 'stripe', 'external_id' => 'sub_trial', 'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->subHour()],
            'provider-managed, active, period ended an hour ago' => ['gateway' => 'stripe', 'external_id' => 'sub_active', 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->subHour()],
            'provider-managed, past_due, no grace window' => ['gateway' => 'stripe', 'external_id' => 'sub_past_due', 'status' => SubscriptionStatus::PastDue],
            'provider-managed, cancellation due an hour ago' => ['gateway' => 'stripe', 'external_id' => 'sub_cancelled', 'status' => SubscriptionStatus::Active, 'cancels_at' => now()->subHour()],
            'provider-managed, paused indefinitely' => ['gateway' => 'stripe', 'external_id' => 'sub_paused', 'status' => SubscriptionStatus::Paused],
            'provider-managed, paused, resume due an hour ago' => ['gateway' => 'stripe', 'external_id' => 'sub_resuming', 'status' => SubscriptionStatus::Paused, 'pause_ends_at' => now()->subHour()],
        ];

        return collect($rows)->map(fn (array $attributes) => Subscription::create([
            // No gateway and no saved card: process-recurring-charges must not try to take money
            // from this matrix, only finalize what is due.
            'gateway' => null,
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]));
    }
}
