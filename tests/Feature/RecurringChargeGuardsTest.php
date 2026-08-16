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
