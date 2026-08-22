<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionRenewed;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * A payment outcome only ever moves a subscription that is still running. Deliveries are late and
 * replayed all the time, and a subscription the customer cancelled or paused must not be dragged
 * back into a paying state (or into dunning) by one of them.
 */
class SubscriptionOutcomeGuardsTest extends TestCase
{
    public function test_a_late_failure_does_not_put_a_cancelled_subscription_back_into_dunning(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Canceled);

        PaymentFailed::dispatch($this->renewalPayment($subscription, PaymentStatus::Failed));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertSame(0, $subscription->recurring_attempts);
        $this->assertNull($subscription->grace_ends_at);
    }

    public function test_a_late_failure_does_not_resurrect_a_paused_subscription_as_past_due(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Paused);

        PaymentFailed::dispatch($this->renewalPayment($subscription, PaymentStatus::Failed));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Paused, $subscription->status);
        $this->assertFalse($subscription->isActive());
    }

    public function test_a_late_success_does_not_revive_a_cancelled_subscription(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $subscription = $this->subscription(SubscriptionStatus::Canceled);
        $periodEnd = $subscription->current_period_ends_at;

        PaymentSucceeded::dispatch($this->renewalPayment($subscription, PaymentStatus::Paid));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertEquals($periodEnd, $subscription->current_period_ends_at);
        Event::assertNotDispatched(SubscriptionRenewed::class);
    }

    public function test_a_late_success_does_not_cut_a_scheduled_pause_short(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Paused);
        $subscription->update(['pause_ends_at' => now()->addMonth()]);

        PaymentSucceeded::dispatch($this->renewalPayment($subscription, PaymentStatus::Paid));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Paused, $subscription->status);
        $this->assertNotNull($subscription->pause_ends_at);
    }

    public function test_a_past_due_subscription_still_renews_on_success(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::PastDue);
        $subscription->update(['recurring_attempts' => 2, 'grace_ends_at' => now()->addDays(3)]);
        $periodEnd = $subscription->current_period_ends_at;

        PaymentSucceeded::dispatch($this->renewalPayment($subscription, PaymentStatus::Paid));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->current_period_ends_at->greaterThan($periodEnd));
        $this->assertSame(0, $subscription->recurring_attempts);
        $this->assertNull($subscription->grace_ends_at);
    }

    private function renewalPayment(Subscription $subscription, PaymentStatus $status): Payment
    {
        return Payment::create([
            'status' => $status,
            'type' => 'charge',
            'gateway' => 'stripe',
            'amount' => 2900,
            'currency' => 'USD',
            'payable_type' => $subscription->getMorphClass(),
            'payable_id' => $subscription->id,
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]);
    }

    private function subscription(SubscriptionStatus $status): Subscription
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
            'status' => $status,
            'gateway' => 'stripe',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'current_period_ends_at' => now()->subDay(),
        ]);
    }
}
