<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Hutko's programmable RRO (docs.hutko.org/uk/docs/page/50): the basket goes as `reservation_data`,
 * a base64'd JSON, with prices in DECIMAL major units even though the request's own `amount` is
 * minor units.
 */
class HutkoFiscalizationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.hutko.merchant_id', '1');
        $app['config']->set('billing.gateways.hutko.secret_key', 'secret_test');
    }

    public function test_receipt_items_become_base64_reservation_data_in_major_units(): void
    {
        $this->fakeCheckout();

        Billing::driver('hutko')->charge($this->payment(), new ChargeOptions(receiptItems: [
            ['name' => 'Мигдаль жар.', 'qty' => 0.2, 'unitAmount' => 70000, 'sku' => 'ALM'],
            ['name' => 'Кешью очищ.', 'qty' => 2, 'unitAmount' => 85000, 'sku' => 'CSH'],
        ]));

        Http::assertSent(function ($request) {
            $decoded = json_decode(base64_decode($request['request']['reservation_data']), true);

            // Whole amounts come back as ints after the JSON round-trip (700.0 encodes as `700`) —
            // Hutko's own docs list `15` alongside `400.00` as valid `price` values, so that form
            // is accepted; don't "fix" this by string-formatting the numbers.
            return $decoded === ['products' => [
                ['id' => 1, 'name' => 'Мигдаль жар.', 'price' => 700, 'total_amount' => 140, 'quantity' => 0.2],
                ['id' => 2, 'name' => 'Кешью очищ.', 'price' => 850, 'total_amount' => 1700, 'quantity' => 2],
            ]];
        });
    }

    public function test_no_receipt_items_omits_reservation_data_entirely(): void
    {
        $this->fakeCheckout();

        Billing::driver('hutko')->charge($this->payment());

        Http::assertSent(fn ($request) => ! array_key_exists('reservation_data', $request['request']));
    }

    private function fakeCheckout(): void
    {
        Http::fake([
            'https://pay.hutko.org/api/checkout/url' => Http::response([
                'response' => ['response_status' => 'success', 'checkout_url' => 'https://pay.hutko.org/checkout?token=x'],
            ]),
        ]);
    }

    private function payment(): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'hutko',
            'amount' => 184000,
            'currency' => 'UAH',
            'payable_type' => TestUser::class,
            'payable_id' => $user->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
