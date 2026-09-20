<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentFailed;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionRenewed;
use Fomvasss\Billing\Events\TrialEnded;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * `incomplete` is the row a checkout creates so the payment has something to point at: no access,
 * invisible to every scheduler, and activated by the first successful payment. Before it existed
 * the only way to spell that was a `trialing` row with an already-past trial_ends_at — which
 * billing:expire-trials reads as a lapsed trial and moves to `ended` (hourly, so within the hour),
 * after which the payment the customer was still making is refused by recordRenewalSuccess().
 */
class IncompleteSubscriptionTest extends TestCase
{
    public function test_it_grants_no_access(): void
    {
        $subscription = $this->incomplete();

        $this->assertFalse($subscription->isActive());
        $this->assertFalse($subscription->onTrial());
        $this->assertEmpty(Subscription::query()->active()->pluck('id')->all());
    }

    public function test_the_trial_expiry_pass_leaves_it_alone(): void
    {
        Event::fake([TrialEnded::class]);

        $subscription = $this->incomplete();

        $this->artisan('billing:expire-trials')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Incomplete, $subscription->fresh()->status);
        Event::assertNotDispatched(TrialEnded::class);
    }

    public function test_the_first_payment_activates_it(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $subscription = $this->incomplete();

        PaymentSucceeded::dispatch($this->checkoutPayment($subscription, PaymentStatus::Paid));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        // The checkout is what decides how the subscription will be paid from now on — without
        // this stamp process-recurring-charges (whereNotNull gateway) would never renew it.
        $this->assertSame('stripe', $subscription->gateway);
        $this->assertTrue($subscription->current_period_ends_at->isFuture());
        $this->assertTrue($subscription->isActive());

        Event::assertDispatched(
            SubscriptionRenewed::class,
            fn (SubscriptionRenewed $event) => $event->previousStatus === SubscriptionStatus::Incomplete,
        );
    }

    public function test_a_declined_card_at_checkout_leaves_it_waiting_instead_of_dunning_it(): void
    {
        $subscription = $this->incomplete();

        PaymentFailed::dispatch($this->checkoutPayment($subscription, PaymentStatus::Failed));

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Incomplete, $subscription->status);
        $this->assertSame(0, $subscription->recurring_attempts);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertNull($subscription->cancels_at);
    }

    private function checkoutPayment(Subscription $subscription, PaymentStatus $status): Payment
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

    private function incomplete(): Subscription
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
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
            'status' => SubscriptionStatus::Incomplete,
            // Nothing is known about how it will be paid until the checkout resolves.
            'gateway' => null,
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
