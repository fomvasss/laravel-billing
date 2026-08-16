<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * charge() uses `?behavior=offline` (a host2host Purchase, documented for mobile apps but usable
 * from any server) instead of handing the browser a client-submitted checkout form — the same
 * approach `dropshop`'s production WayForPay integration uses. `PaymentResult::$url` is populated,
 * `$form` is not, matching Monobank/Stripe/Hutko's shape instead of LiqPay's (LiqPay has no
 * equivalent — no way around the client-submitted form there).
 */
class WayForPayChargeTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'test_merchant');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'secret_test');
        $app['config']->set('billing.return_urls.success', 'https://example.test/thanks');
    }

    public function test_charge_returns_a_ready_url_instead_of_a_form(): void
    {
        Http::fake([
            'https://secure.wayforpay.com/pay*' => Http::response(['url' => 'https://secure.wayforpay.com/page?vkh=abc123']),
        ]);

        $user = TestUser::create(['name' => 'Buyer']);
        $payment = Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'wayforpay',
            'amount' => 10000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $result = Billing::driver('wayforpay')->charge($payment);

        $this->assertSame('https://secure.wayforpay.com/page?vkh=abc123', $result->url);
        $this->assertNull($result->form);

        Http::assertSent(fn ($request) => $request->url() === 'https://secure.wayforpay.com/pay?behavior=offline'
            && $request['merchantAccount'] === 'test_merchant'
            && $request['orderReference'] === (string) $payment->id
            // explicit TTL (default 1440 min) — payment_url_expires_at must be a real number here
            && $request['orderLifetime'] === 1440 * 60);

        $this->assertNotNull($result->expiresAt);
        $this->assertTrue($result->expiresAt > now()->addMinutes(1400));
    }
}
