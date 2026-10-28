<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Enums\PaymentInitiation;
use Fomvasss\Billing\Exceptions\TooManyCheckoutsException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;

/**
 * checkout_rate_limit — card-testing protection on every checkout a visitor can open: per
 * billable, per customerIp, and per visitor IP on the public pay link. Off unless configured.
 */
class CheckoutRateLimitTest extends TestCase
{
    public function test_it_is_off_by_default(): void
    {
        $user = TestUser::create(['name' => 'Buyer']);

        foreach (range(1, 20) as $i) {
            Billing::charge($this->payment($user));
        }

        $this->assertSame(20, Payment::count());
    }

    public function test_checkouts_beyond_the_limit_are_refused_per_billable(): void
    {
        config(['billing.checkout_rate_limit.max_attempts' => 2]);

        $user = TestUser::create(['name' => 'Buyer']);
        Billing::charge($this->payment($user));
        Billing::charge($this->payment($user));

        try {
            Billing::charge($this->payment($user));
            $this->fail('The third checkout was not refused.');
        } catch (TooManyCheckoutsException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertGreaterThan(0, $e->retryAfter);
        }

        Billing::charge($this->payment(TestUser::create(['name' => 'Another buyer'])));
        $this->addToAssertionCount(1);
    }

    public function test_a_customer_ip_is_counted_across_billables(): void
    {
        config(['billing.checkout_rate_limit.max_attempts' => 2]);
        $options = new ChargeOptions(customerIp: '203.0.113.7');

        Billing::charge($this->payment(TestUser::create(['name' => 'Guest 1'])), $options);
        Billing::charge($this->payment(TestUser::create(['name' => 'Guest 2'])), $options);

        $this->expectException(TooManyCheckoutsException::class);

        Billing::charge($this->payment(TestUser::create(['name' => 'Guest 3'])), $options);
    }

    public function test_an_automatic_initiation_is_not_limited(): void
    {
        config(['billing.checkout_rate_limit.max_attempts' => 1]);

        $user = TestUser::create(['name' => 'Buyer']);
        $options = new ChargeOptions(initiation: PaymentInitiation::Automatic);

        Billing::charge($this->payment($user), $options);
        Billing::charge($this->payment($user), $options);

        $this->assertSame(2, Payment::count());
    }

    public function test_the_pay_link_answers_429_once_the_visitor_ip_is_over_the_limit(): void
    {
        config(['billing.checkout_rate_limit.max_attempts' => 2]);

        foreach (range(1, 2) as $i) {
            $payment = $this->payment(TestUser::create(['name' => "Buyer {$i}"]), ['status' => 'failed']);
            $this->get(route('billing.pay', $payment))->assertStatus(303);
        }

        $payment = $this->payment(TestUser::create(['name' => 'Buyer 3']), ['status' => 'failed']);

        $this->get(route('billing.pay', $payment))
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_the_pay_link_redirecting_to_a_live_checkout_is_not_counted(): void
    {
        config(['billing.checkout_rate_limit.max_attempts' => 1]);

        $payment = $this->payment(TestUser::create(['name' => 'Buyer']), [
            'payment_url' => 'https://pay.example/live',
            'payment_url_expires_at' => now()->addHour(),
        ]);

        foreach (range(1, 3) as $i) {
            $this->get(route('billing.pay', $payment))->assertRedirect('https://pay.example/live');
        }
    }

    private function payment(TestUser $user, array $attributes = []): Payment
    {
        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'fake',
            'amount' => 5000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
