<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentCanceled;
use Fomvasss\Billing\Events\SubscriptionPaymentFailed;
use Fomvasss\Billing\Events\SubscriptionRenewed;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Every way a renewal used to get stuck as a pending Payment nobody could ever resolve — which the
 * pending-renewal guard then turns into a subscription that is never charged, never dunned and
 * never cancelled, sitting `active` on a period that ended long ago.
 */
class StuckRenewalTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_a_metered_period_with_no_usage_advances_without_touching_the_gateway(): void
    {
        Event::fake([SubscriptionRenewed::class]);
        Http::fake();

        $subscription = $this->dueSubscription(['pricing_type' => 'metered']);
        $periodEnd = $subscription->current_period_ends_at;

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $subscription->refresh();

        $this->assertSame(0, Payment::query()->count(), 'a zero debit every gateway rejects must not be attempted');
        $this->assertTrue($subscription->current_period_ends_at->greaterThan($periodEnd));
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
        Http::assertNothingSent();
    }

    /** Whether a card is on file is irrelevant to a period that owes nothing. */
    public function test_a_metered_period_with_no_usage_advances_even_without_a_saved_card(): void
    {
        Event::fake([SubscriptionRenewed::class, SubscriptionPaymentFailed::class]);
        Http::fake();

        $subscription = $this->dueSubscription(['pricing_type' => 'metered']);
        PaymentMethod::query()->delete();

        $periodEnd = $subscription->current_period_ends_at;

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(0, $subscription->recurring_attempts);
        $this->assertTrue($subscription->current_period_ends_at->greaterThan($periodEnd));
        Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
        Event::assertNotDispatched(SubscriptionPaymentFailed::class);
    }

    public function test_an_initiation_that_never_reached_the_gateway_fails_instead_of_hanging_pending(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $subscription = $this->dueSubscription();

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $payment = Payment::query()->sole();
        $subscription->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->recurring_attempts);

        // The whole point: the next attempt is free to run once the retry interval passes.
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'processing'])]);
        $this->travel(25)->hours();

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $this->assertSame(2, Payment::query()->count());
    }

    public function test_reconciliation_writes_off_a_renewal_that_never_got_a_gateway_reference(): void
    {
        Event::fake([PaymentCanceled::class, SubscriptionPaymentFailed::class]);

        $subscription = $this->dueSubscription();
        $payment = $this->pendingRenewal($subscription);

        $this->travel(61)->minutes();

        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Canceled, $payment->fresh()->status);
        Event::assertDispatchedTimes(PaymentCanceled::class, 1);
    }

    public function test_a_canceled_renewal_outcome_counts_as_a_failed_attempt(): void
    {
        $subscription = $this->dueSubscription();
        $payment = $this->pendingRenewal($subscription);

        $this->travel(61)->minutes();
        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->recurring_attempts);
        $this->assertNotNull($subscription->next_retry_at);
    }

    /** A consumer-created invoice awaiting payment looks the same on paper — it must be left alone. */
    public function test_reconciliation_leaves_a_not_yet_charged_one_off_payment_pending(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $payment = Payment::create([
            'status' => PaymentStatus::Pending,
            'type' => 'charge',
            'gateway' => 'stripe',
            'amount' => 2900,
            'currency' => 'USD',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $this->travel(61)->minutes();
        $this->artisan('billing:reconcile-pending-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    private function pendingRenewal(Subscription $subscription): Payment
    {
        return Payment::create([
            'status' => PaymentStatus::Pending,
            'type' => 'charge',
            'gateway' => $subscription->gateway,
            'amount' => 2900,
            'currency' => 'USD',
            'payable_type' => $subscription->getMorphClass(),
            'payable_id' => $subscription->id,
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]);
    }

    private function dueSubscription(array $priceAttributes = []): Subscription
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
            ...$priceAttributes,
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
            'external_customer_id' => 'cus_' . uniqid(),
            'external_id' => 'pm_' . uniqid(),
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        return $subscription;
    }
}
