<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

/**
 * Opaque, consumer-controlled — the package never reads or writes it (same idea as Plan.meta).
 * A place to say "what is this payment for" without a dedicated Payable model when one isn't
 * otherwise warranted. See "Recipes" in README.
 */
class PaymentMetaTest extends TestCase
{
    public function test_meta_round_trips_as_an_array(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);

        $payment = Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 500,
            'currency_code' => 'USD',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            'meta' => ['product' => 'ai_tokens', 'tokens' => 10000],
        ]);

        $this->assertSame(['product' => 'ai_tokens', 'tokens' => 10000], $payment->fresh()->meta);
    }

    public function test_meta_defaults_to_null(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);

        $payment = Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 500,
            'currency_code' => 'USD',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $this->assertNull($payment->fresh()->meta);
    }
}
