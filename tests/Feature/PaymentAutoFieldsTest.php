<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestOrder;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

class PaymentAutoFieldsTest extends TestCase
{
    public function test_paid_at_is_stamped_only_when_status_becomes_paid(): void
    {
        $payment = $this->makePayment();
        $this->assertNull($payment->paid_at);

        $payment->update(['status' => 'paid']);
        $this->assertNotNull($payment->paid_at);

        // failing after having been paid clears it back — paid_at means "currently paid", not "was ever paid"
        $payment->update(['status' => 'failed']);
        $this->assertNull($payment->fresh()->paid_at);
    }

    private function makePayment(): Payment
    {
        $order = TestOrder::create(['title' => 'Order']);
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'amount' => 1000,
            'currency' => 'UAH',
            'payable_type' => TestOrder::class,
            'payable_id' => $order->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
