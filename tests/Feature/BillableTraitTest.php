<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

/** The Concerns\Billable accessors — the consumer-side view onto payments/subscriptions/cards. */
class BillableTraitTest extends TestCase
{
    public function test_relations_return_only_this_billables_rows(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $other = TestUser::create(['name' => 'Someone else']);

        $this->payment($user);
        $this->payment($user, ['status' => 'paid']);
        $this->payment($other);
        $this->method($user);
        $this->subscription($user, ['status' => SubscriptionStatus::Active]);

        $this->assertCount(2, $user->payments);
        $this->assertCount(1, $user->payments()->paid()->get());
        $this->assertCount(1, $user->paymentMethods);
        $this->assertCount(1, $user->subscriptions);
        $this->assertCount(1, $other->payments);
    }

    public function test_default_payment_method_as_property_and_per_gateway(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);

        $this->method($user, ['gateway' => 'monobank', 'is_default' => false]);
        $default = $this->method($user, ['gateway' => 'monobank', 'is_default' => true]);
        $stripeDefault = $this->method($user, ['gateway' => 'stripe', 'is_default' => true]);

        $this->assertTrue(in_array($user->defaultPaymentMethod->id, [$default->id, $stripeDefault->id], true));
        $this->assertTrue($user->defaultPaymentMethodFor('monobank')->is($default));
        $this->assertTrue($user->defaultPaymentMethodFor('stripe')->is($stripeDefault));
        $this->assertNull($user->defaultPaymentMethodFor('liqpay'));
    }

    public function test_active_subscription_matches_is_active_semantics_and_filters_by_plan_code(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);

        $this->subscription($user, ['status' => SubscriptionStatus::Canceled], planCode: 'pro');
        $inGrace = $this->subscription($user, ['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->addDay()], planCode: 'pro');
        $addon = $this->subscription($user, ['status' => SubscriptionStatus::Active], planCode: 'ai-addon');

        $this->assertTrue($user->hasActiveSubscription());
        $this->assertTrue($user->hasActiveSubscription('pro'));
        $this->assertTrue($user->activeSubscription('pro')->is($inGrace));
        $this->assertTrue($user->activeSubscription('ai-addon')->is($addon));
        $this->assertFalse($user->hasActiveSubscription('unknown-plan'));

        $inGrace->update(['grace_ends_at' => now()->subDay()]);
        $this->assertFalse($user->hasActiveSubscription('pro'));
    }

    private function payment(TestUser $user, array $attributes = []): Payment
    {
        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }

    private function method(TestUser $user, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create([
            'gateway' => 'fake',
            'external_customer_id' => 'cus_' . uniqid(),
            'external_id' => 'pm_' . uniqid(),
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }

    private function subscription(TestUser $user, array $attributes = [], string $planCode = 'pro'): Subscription
    {
        $plan = Plan::firstOrCreate(['code' => $planCode], ['name' => $planCode]);
        $price = Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 10000, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);

        return Subscription::create([
            'gateway' => 'fake',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
