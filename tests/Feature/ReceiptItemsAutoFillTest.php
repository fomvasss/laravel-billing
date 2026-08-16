<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Contracts\HasReceiptItems;
use Fomvasss\Billing\Contracts\PaymentGatewayContract;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Database\Eloquent\Model;

/**
 * BillingManager::charge() fills ChargeOptions::$receiptItems from $payment->payable->receiptItems()
 * when the caller didn't already pass one and $payable implements HasReceiptItems — the fiscal
 * basket a driver needs, without every caller repeating the same instanceof check.
 */
class ReceiptItemsAutoFillTest extends TestCase
{
    public function test_charge_fills_receipt_items_from_the_payable_when_not_set_explicitly(): void
    {
        RecordingGateway::$receivedOptions = null;
        Billing::extend('recording', RecordingGateway::class);

        $user = TestUser::create(['name' => 'Buyer']);
        $order = $this->orderWithReceiptItems();

        $payment = Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'recording',
            'amount' => 5000,
            'currency' => 'UAH',
            'payable_type' => $order::class,
            'payable_id' => $order->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::charge($payment);

        $this->assertSame(
            [['name' => 'Widget', 'qty' => 2, 'unitAmount' => 2500, 'sku' => 'WID-1']],
            RecordingGateway::$receivedOptions->receiptItems,
        );
    }

    public function test_an_explicitly_passed_receipt_items_is_not_overridden(): void
    {
        RecordingGateway::$receivedOptions = null;
        Billing::extend('recording', RecordingGateway::class);

        $user = TestUser::create(['name' => 'Buyer']);
        $order = $this->orderWithReceiptItems();

        $payment = Payment::create([
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'recording',
            'amount' => 5000,
            'currency' => 'UAH',
            'payable_type' => $order::class,
            'payable_id' => $order->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        $explicit = [['name' => 'Custom', 'qty' => 1, 'unitAmount' => 100, 'sku' => null]];

        Billing::charge($payment, new ChargeOptions(receiptItems: $explicit));

        $this->assertSame($explicit, RecordingGateway::$receivedOptions->receiptItems);
    }

    private function orderWithReceiptItems(): Model
    {
        $order = new class extends Model implements HasReceiptItems {
            protected $table = 'test_orders';

            protected $guarded = ['id'];

            public function receiptItems(): array
            {
                return [['name' => 'Widget', 'qty' => 2, 'unitAmount' => 2500, 'sku' => 'WID-1']];
            }
        };

        $order->save();

        return $order;
    }
}

class RecordingGateway implements PaymentGatewayContract
{
    public static ?ChargeOptions $receivedOptions = null;

    public function __construct(protected array $credentials, protected string $gatewayName) {}

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        self::$receivedOptions = $options;

        return new PaymentResult(url: 'https://example.test/pay');
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        return new WebhookResult(type: \Fomvasss\Billing\Enums\WebhookEventType::Ignored, status: 'ignored');
    }

    public static function label(): string
    {
        return 'Recording';
    }

    public static function credentialFields(): array
    {
        return [];
    }

    public static function supportedCurrencies(): array
    {
        return ['UAH'];
    }
}
