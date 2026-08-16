<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Events\CheckoutReturned;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The return route bridges "gateway sends the browser back" and "the app's own success/failed
 * pages": fires CheckoutReturned, 303-redirects to config('billing.return_urls.*') with
 * ?payment={id}. GET+POST — WayForPay/Hutko return the customer via an auto-submitted POST form.
 */
class CheckoutReturnTest extends TestCase
{
    public function test_a_get_return_fires_the_event_and_redirects_to_the_success_page(): void
    {
        Event::fake([CheckoutReturned::class]);

        $payment = $this->pendingPayment();

        $response = $this->get(route('billing.return', ['payment' => $payment, 'outcome' => 'success']));

        $response->assertStatus(303);
        $response->assertRedirect('https://example.test/thanks?payment=' . $payment->id);
        Event::assertDispatched(CheckoutReturned::class, fn ($event) => $event->payment->is($payment) && $event->outcome === 'success');
    }

    public function test_a_post_return_needs_no_csrf_token_and_lands_on_the_failed_page_as_get(): void
    {
        Event::fake([CheckoutReturned::class]);

        $payment = $this->pendingPayment();

        // WayForPay/Hutko shape: the gateway auto-submits a POST form — no session, no CSRF token.
        $response = $this->post(
            route('billing.return', ['payment' => $payment, 'outcome' => 'failed']),
            ['transactionStatus' => 'Declined', 'reasonCode' => '1105'],
        );

        $response->assertStatus(303); // 303 turns the gateway's POST into a plain GET on the final page
        $response->assertRedirect('https://example.test/fail?payment=' . $payment->id);
        Event::assertDispatched(CheckoutReturned::class, fn ($event) => $event->outcome === 'failed'
            && ($event->data['transactionStatus'] ?? null) === 'Declined');
    }

    public function test_unknown_payment_or_outcome_is_a_404(): void
    {
        $payment = $this->pendingPayment();

        $this->get('/billing/return/0198c0de-0000-7000-8000-000000000000/success')->assertNotFound();
        $this->get(route('billing.return', ['payment' => $payment, 'outcome' => 'weird']))->assertNotFound();
    }

    public function test_drivers_point_the_gateway_at_the_return_route_by_default(): void
    {
        config()->set('billing.gateways.monobank.token', 'test-token');
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response(['invoiceId' => 'inv_1', 'pageUrl' => 'https://pay.mbnk.biz/x']),
        ]);

        $payment = $this->pendingPayment();

        Billing::charge($payment);

        Http::assertSent(fn ($request) => $request['redirectUrl'] === route('billing.return', ['payment' => $payment, 'outcome' => 'success']));
    }

    public function test_return_params_travel_from_charge_options_to_the_final_frontend_url(): void
    {
        config()->set('billing.gateways.monobank.token', 'test-token');
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response(['invoiceId' => 'inv_1', 'pageUrl' => 'https://pay.mbnk.biz/x']),
        ]);

        $payment = $this->pendingPayment();

        Billing::charge($payment, new \Fomvasss\Billing\DTO\ChargeOptions(
            returnParams: ['order' => '1042'],
        ));

        // Driver put them on the proxy URL...
        $proxyUrl = route('billing.return', ['payment' => $payment, 'outcome' => 'success', 'order' => '1042']);
        Http::assertSent(fn ($request) => $request['redirectUrl'] === $proxyUrl);

        // ...and the proxy forwards them (plus payment) onto the final page.
        $this->get($proxyUrl)
            ->assertStatus(303)
            ->assertRedirect('https://example.test/thanks?order=1042&payment=' . $payment->id);
    }

    public function test_an_explicit_charge_options_url_bypasses_the_return_route(): void
    {
        config()->set('billing.gateways.monobank.token', 'test-token');
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response(['invoiceId' => 'inv_1', 'pageUrl' => 'https://pay.mbnk.biz/x']),
        ]);

        $payment = $this->pendingPayment();

        Billing::charge($payment, new \Fomvasss\Billing\DTO\ChargeOptions(
            successUrl: 'https://shop.test/thanks?order=1042',
        ));

        Http::assertSent(fn ($request) => $request['redirectUrl'] === 'https://shop.test/thanks?order=1042');
    }

    private function pendingPayment(): Payment
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
        ]);
    }
}
