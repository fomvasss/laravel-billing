<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Events\TrialWillEnd;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * subscriptions.external_id is the ownership marker (Subscription::isProviderManaged()): non-null
 * means the gateway runs the lifecycle natively and reports via webhooks, so every package
 * scheduler must leave the row alone. Per-SUBSCRIPTION, not per-gateway — the same Stripe merchant
 * can run provider-managed and package-managed subscriptions side by side, which is exactly what
 * these tests pin: identical rows except external_id, opposite treatment.
 */
class ProviderManagedSubscriptionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_recurring_charges_skip_provider_managed_but_charge_package_managed(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'processing']),
        ]);

        $provider = $this->dueSubscription(externalId: 'sub_stripe_1');
        $local = $this->dueSubscription();

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $this->assertSame(0, Payment::query()->where('payable_id', $provider->id)->count());
        $this->assertSame(1, Payment::query()->where('payable_id', $local->id)->count());
    }

    public function test_period_end_cancellation_is_not_finalized_locally_for_provider_managed(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        Http::fake();

        $subscription = $this->dueSubscription(externalId: 'sub_stripe_1');
        $subscription->update(['cancels_at' => now()->subHour()]);

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        // stays active until the provider's own `canceled` webhook lands
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        Event::assertNotDispatched(SubscriptionCancelled::class);
    }

    public function test_expire_trials_leaves_provider_managed_trials_alone(): void
    {
        Event::fake([TrialWillEnd::class]);

        $provider = $this->trialSubscription(externalId: 'sub_stripe_1');
        $local = $this->trialSubscription();

        $this->artisan('billing:expire-trials')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Trialing, $provider->fresh()->status);
        $this->assertSame(SubscriptionStatus::Ended, $local->fresh()->status);
    }

    public function test_trial_notices_are_not_sent_for_provider_managed_trials(): void
    {
        Event::fake([TrialWillEnd::class]);
        config(['billing.trial_ending_notices' => ['3 days']]);

        $provider = $this->trialSubscription(externalId: 'sub_stripe_1', endsAt: now()->addDay());
        $local = $this->trialSubscription(endsAt: now()->addDay());

        $this->artisan('billing:expire-trials')->assertSuccessful();

        Event::assertDispatchedTimes(TrialWillEnd::class, 1);
        Event::assertDispatched(TrialWillEnd::class, fn (TrialWillEnd $event) => $event->subscription->is($local));
    }

    public function test_is_provider_managed_reads_external_id(): void
    {
        $this->assertTrue($this->dueSubscription(externalId: 'sub_1')->isProviderManaged());
        $this->assertFalse($this->dueSubscription()->isProviderManaged());
    }

    private function dueSubscription(?string $externalId = null): Subscription
    {
        return $this->makeSubscription([
            'status' => SubscriptionStatus::Active,
            'external_id' => $externalId,
            'current_period_ends_at' => now()->subDay(),
        ]);
    }

    private function trialSubscription(?string $externalId = null, ?\DateTimeInterface $endsAt = null): Subscription
    {
        return $this->makeSubscription([
            'status' => SubscriptionStatus::Trialing,
            'external_id' => $externalId,
            'trial_ends_at' => $endsAt ?? now()->subHour(),
        ]);
    }

    private function makeSubscription(array $attributes): Subscription
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => 'stripe',
            'currency' => 'USD',
            'amount' => 2900,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        PaymentMethod::create([
            'gateway' => 'stripe',
            'external_customer_id' => 'cus_' . uniqid(),
            'external_id' => 'pm_' . uniqid(),
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        return Subscription::create($attributes + [
            'gateway' => 'stripe',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
