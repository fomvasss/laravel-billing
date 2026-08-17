<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionCancelled;
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
 * The money-safety guards of billing:process-recurring-charges: cancel(atPeriodEnd) actually
 * takes effect instead of billing another period, an unresolved pending renewal blocks a second
 * (double) charge, and one broken gateway doesn't strand the rest of the batch.
 */
class RecurringChargeGuardsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_a_period_end_cancelled_subscription_is_finalized_not_charged(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        Http::fake();

        $subscription = $this->dueStripeSubscription();
        $subscription->update(['cancels_at' => $subscription->current_period_ends_at]);

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);
        $this->assertSame(0, Payment::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_unresolved_pending_renewal_blocks_a_second_charge(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_999', 'status' => 'processing']),
        ]);

        $subscription = $this->dueStripeSubscription();

        // Two runs, webhook never arrives in between — the second run must NOT debit the card again.
        $this->artisan('billing:process-recurring-charges')->assertSuccessful();
        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $this->assertSame(1, Payment::query()
            ->where('payable_type', Subscription::class)
            ->where('payable_id', $subscription->id)
            ->count());
        Http::assertSentCount(1);
    }

    public function test_a_failed_attempt_is_not_retried_until_the_retry_interval_passes(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_999', 'status' => 'processing']),
        ]);

        $subscription = $this->dueStripeSubscription();
        // The state HandleSubscriptionPaymentOutcome leaves after a failed renewal.
        $subscription->update([
            'status' => SubscriptionStatus::PastDue,
            'recurring_attempts' => 1,
            'grace_ends_at' => now()->addDays(3),
            'next_retry_at' => now()->addDay(),
        ]);

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();
        Http::assertNothingSent();

        $this->travel(25)->hours();

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_a_due_subscription_with_no_saved_card_enters_dunning_instead_of_stalling(): void
    {
        Event::fake([\Fomvasss\Billing\Events\SubscriptionPaymentFailed::class]);
        Http::fake();

        $subscription = $this->dueSubscriptionWithoutCard();

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->recurring_attempts);
        $this->assertNotNull($subscription->grace_ends_at);
        $this->assertNotNull($subscription->next_retry_at);
        Event::assertDispatchedTimes(\Fomvasss\Billing\Events\SubscriptionPaymentFailed::class, 1);
        Http::assertNothingSent(); // never even attempted to call the gateway — there's no card to charge

        // next_retry_at pacing applies exactly like a real decline — no immediate re-pick.
        $this->artisan('billing:process-recurring-charges')->assertSuccessful();
        $this->assertSame(1, $subscription->fresh()->recurring_attempts);
    }

    public function test_a_card_less_subscription_is_cancelled_after_max_attempts_like_a_real_decline(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        $subscription = $this->dueSubscriptionWithoutCard();

        $this->artisan('billing:process-recurring-charges')->assertSuccessful(); // attempt 1 → past_due
        $this->travel(25)->hours();
        $this->artisan('billing:process-recurring-charges')->assertSuccessful(); // attempt 2 → past_due
        $this->travel(25)->hours();
        $this->artisan('billing:process-recurring-charges')->assertSuccessful(); // attempt 3 = max → canceled

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);
    }

    public function test_subscription_access_suspended_fires_once_when_grace_access_is_off(): void
    {
        Event::fake([\Fomvasss\Billing\Events\SubscriptionAccessSuspended::class]);
        config(['billing.grace_access' => false]);

        // no saved card → recordRenewalFailure() runs synchronously within the command, no HTTP
        // round trip and no dependence on a webhook ever arriving (unlike a real card decline).
        $subscription = $this->dueSubscriptionWithoutCard();

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();
        $this->assertFalse($subscription->fresh()->isActive()); // access cut immediately

        // a second failed retry within the same past_due episode must NOT re-fire the event —
        // access was already off after the first one.
        $this->travel(25)->hours();
        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        Event::assertDispatchedTimes(\Fomvasss\Billing\Events\SubscriptionAccessSuspended::class, 1);
    }

    public function test_subscription_access_suspended_does_not_fire_when_grace_access_stays_on(): void
    {
        Event::fake([\Fomvasss\Billing\Events\SubscriptionAccessSuspended::class]);

        $subscription = $this->dueSubscriptionWithoutCard();
        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $this->assertTrue($subscription->fresh()->isActive()); // default: grace window still covers access
        Event::assertNotDispatched(\Fomvasss\Billing\Events\SubscriptionAccessSuspended::class);
    }

    public function test_one_failing_subscription_does_not_strand_the_rest_of_the_batch(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_999', 'status' => 'processing']),
        ]);

        // Monobank is deliberately left without a token — its charge throws a credentials
        // BillingException. Created first so it's processed first (UUID v7 order).
        $broken = $this->dueSubscription('monobank', 'UAH');
        $healthy = $this->dueStripeSubscription();

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $this->assertSame(1, Payment::query()
            ->where('payable_type', Subscription::class)
            ->where('payable_id', $healthy->id)
            ->count());
    }

    private function dueStripeSubscription(): Subscription
    {
        return $this->dueSubscription('stripe', 'USD');
    }

    /** Same as dueSubscription() but WITHOUT a saved card — the Scenario 3 case. */
    private function dueSubscriptionWithoutCard(): Subscription
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

        return Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => 'stripe',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->subDay(),
        ]);
    }

    private function dueSubscription(string $gateway, string $currency): Subscription
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => $gateway,
            'currency' => $currency,
            'amount' => 2900,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        $subscription = Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => $gateway,
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->subDay(),
        ]);

        PaymentMethod::create([
            'gateway' => $gateway,
            'external_customer_id' => 'cus_' . uniqid(),
            'external_id' => 'pm_' . uniqid(),
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        return $subscription;
    }
}
