<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestOrder;
use Fomvasss\Billing\Tests\Fixtures\TestTenantUser;
use Fomvasss\Billing\Tests\TestCase;

/**
 * `tenant_id` is derived from the billable, not authored by the caller: the package's own writes
 * (renewals, refunds) carry it, while a hand-written `$billable->payments()->create()` did not —
 * leaving rows that no tenant-scoped query ever sees, with nothing to signal it.
 */
class TenantIdDerivationTest extends TestCase
{
    public function test_a_payment_takes_the_tenant_of_its_billable(): void
    {
        $user = TestTenantUser::create(['name' => 'Buyer']);
        $order = TestOrder::create(['title' => 'Order']);

        // Exactly the shape a consumer writes by hand — billable set, tenant not mentioned
        $payment = $user->payments()->create([
            'status' => 'pending',
            'type' => 'charge',
            'amount' => 1000,
            'currency' => 'UAH',
            'payable_type' => TestOrder::class,
            'payable_id' => $order->id,
        ]);

        $this->assertSame('tenant-1', $payment->tenant_id);
    }

    public function test_a_subscription_and_a_payment_method_take_it_too(): void
    {
        $user = TestTenantUser::create(['name' => 'Buyer']);
        $price = Plan::create(['code' => 'base', 'name' => 'Base'])->prices()->create([
            'currency' => 'UAH',
            'amount' => 10000,
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        $subscription = Subscription::create([
            'status' => SubscriptionStatus::Active,
            'price_id' => $price->id,
            'billable_type' => TestTenantUser::class,
            'billable_id' => $user->id,
        ]);

        $method = PaymentMethod::create([
            'gateway' => 'monobank',
            'external_customer_id' => 'wallet_1',
            'external_id' => 'card_tok_1',
            'billable_type' => TestTenantUser::class,
            'billable_id' => $user->id,
        ]);

        $this->assertSame('tenant-1', $subscription->tenant_id);
        $this->assertSame('tenant-1', $method->tenant_id);
    }

    /** A cross-tenant row is a legitimate record — what the caller passed is never second-guessed. */
    public function test_an_explicit_tenant_is_kept(): void
    {
        $user = TestTenantUser::create(['name' => 'Buyer']);
        $order = TestOrder::create(['title' => 'Order']);

        $payment = $user->payments()->create([
            'status' => 'pending',
            'type' => 'charge',
            'amount' => 1000,
            'currency' => 'UAH',
            'tenant_id' => 'tenant-2',
            'payable_type' => TestOrder::class,
            'payable_id' => $order->id,
        ]);

        $this->assertSame('tenant-2', $payment->tenant_id);
    }

    /** A billable outside multi-tenancy (the trait's null default) leaves the column empty. */
    public function test_a_billable_without_a_tenant_leaves_it_null(): void
    {
        $user = \Fomvasss\Billing\Tests\Fixtures\TestUser::create(['name' => 'Buyer']);
        $order = TestOrder::create(['title' => 'Order']);

        $payment = $user->payments()->create([
            'status' => 'pending',
            'type' => 'charge',
            'amount' => 1000,
            'currency' => 'UAH',
            'payable_type' => TestOrder::class,
            'payable_id' => $order->id,
        ]);

        $this->assertNull($payment->tenant_id);
    }
}
