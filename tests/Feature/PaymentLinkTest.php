<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Events\PaymentLinkOpened;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * billing.pay — the permanent link for emails/invoices: live checkout → straight redirect;
 * stale/failed → fresh charge() first; paid → success page. PaymentLinkOpened on every visit.
 */
class PaymentLinkTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.monobank.token', 'test-token');
    }

    public function test_a_live_checkout_redirects_straight_to_the_gateway_without_recharging(): void
    {
        Event::fake([PaymentLinkOpened::class]);
        Http::fake();

        $payment = $this->payment([
            'payment_url' => 'https://pay.mbnk.biz/live',
            'payment_url_expires_at' => now()->addHour(),
        ]);

        $this->get(route('billing.pay', $payment))
            ->assertStatus(303)
            ->assertRedirect('https://pay.mbnk.biz/live');

        Http::assertNothingSent();
        Event::assertDispatched(PaymentLinkOpened::class, fn ($event) => $event->payment->is($payment));
    }

    public function test_an_expired_checkout_is_reissued_on_the_fly(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response(['invoiceId' => 'inv_new', 'pageUrl' => 'https://pay.mbnk.biz/fresh']),
        ]);

        $payment = $this->payment([
            'payment_url' => 'https://pay.mbnk.biz/stale',
            'payment_url_expires_at' => now()->subMinute(),
        ]);

        $this->get(route('billing.pay', $payment))
            ->assertStatus(303)
            ->assertRedirect('https://pay.mbnk.biz/fresh');

        $this->assertSame('inv_new', $payment->fresh()->external_id);
    }

    public function test_a_failed_payment_gets_a_fresh_checkout_even_if_the_old_link_is_unexpired(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response(['invoiceId' => 'inv_retry', 'pageUrl' => 'https://pay.mbnk.biz/retry']),
        ]);

        $payment = $this->payment([
            'status' => 'failed',
            'payment_url' => 'https://pay.mbnk.biz/declined-dead-end',
            'payment_url_expires_at' => now()->addHour(),
        ]);

        $this->get(route('billing.pay', $payment))
            ->assertStatus(303)
            ->assertRedirect('https://pay.mbnk.biz/retry');
    }

    public function test_a_paid_payment_lands_on_the_success_page(): void
    {
        Http::fake();

        $payment = $this->payment(['status' => 'paid']);

        $this->get(route('billing.pay', $payment))
            ->assertStatus(303)
            ->assertRedirect('https://example.test/thanks?payment=' . $payment->id);

        Http::assertNothingSent();
    }

    public function test_refund_rows_and_unknown_payments_are_404(): void
    {
        $refund = $this->payment(['type' => 'refund', 'status' => 'paid']);

        $this->get(route('billing.pay', $refund))->assertNotFound();
        $this->get('/billing/pay/0198c0de-0000-7000-8000-000000000000')->assertNotFound();
    }

    private function payment(array $attributes = []): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'monobank',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
