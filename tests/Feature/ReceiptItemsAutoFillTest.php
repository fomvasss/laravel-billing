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

        // Totals the payment's own amount — a basket that doesn't is refused (see assertReceiptItemsMatchAmount()).
        $explicit = [['name' => 'Custom', 'qty' => 1, 'unitAmount' => 5000, 'sku' => null]];

        Billing::charge($payment, new ChargeOptions(receiptItems: $explicit));

        $this->assertSame($explicit, RecordingGateway::$receivedOptions->receiptItems);
    }

    /**
     * chargeWithMethod() mirrors charge()'s auto-fill exactly — the fiscalization gap this closes
     * (see "Gateway fee and net amount"-adjacent README/use-cases notes): off-session charges
     * (renewals, overage, top-ups) can now carry a basket too, not just redirect checkouts.
     */
    public function test_charge_with_method_fills_receipt_items_from_the_payable_when_not_set_explicitly(): void
    {
        RecordingGateway::$receivedChargePaymentMethodOptions = null;
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

        $method = \Fomvasss\Billing\Models\PaymentMethod::create([
            'gateway' => 'recording',
            'external_customer_id' => 'cus_1',
            'external_id' => 'pm_1',
            'is_default' => true,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
        ]);

        Billing::chargeWithMethod($payment, $method);

        $this->assertSame(
            [['name' => 'Widget', 'qty' => 2, 'unitAmount' => 2500, 'sku' => 'WID-1']],
            RecordingGateway::$receivedChargePaymentMethodOptions->receiptItems,
        );
    }

    public function test_charge_with_method_does_not_auto_fill_for_a_subscription_payable(): void
    {
        // The documented boundary: a renewal's payable is always the package's own Subscription,
        // which deliberately does NOT implement HasReceiptItems — see chargeWithMethod()'s docblock.
        RecordingGateway::$receivedChargePaymentMethodOptions = null;
        Billing::extend('recording', RecordingGateway::class);

        $user = TestUser::create(['name' => 'Buyer']);
        $plan = \Fomvasss\Billing\Models\Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = \Fomvasss\Billing\Models\Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 5000, 'pricing_type' => 'flat', 'interval' => 'month']);
        $subscription = \Fomvasss\Billing\Models\Subscription::create([
            'status' => 'active', 'gateway' => 'recording', 'price_id' => $price->id,
            'billable_type' => TestUser::class, 'billable_id' => $user->id,
        ]);

        $payment = Payment::create([
            'status' => 'pending', 'type' => 'charge', 'gateway' => 'recording',
            'amount' => 5000, 'currency' => 'UAH',
            'payable_type' => $subscription->getMorphClass(), 'payable_id' => $subscription->id,
            'billable_type' => TestUser::class, 'billable_id' => $user->id,
        ]);

        $method = \Fomvasss\Billing\Models\PaymentMethod::create([
            'gateway' => 'recording', 'external_customer_id' => 'cus_1', 'external_id' => 'pm_1', 'is_default' => true,
            'billable_type' => TestUser::class, 'billable_id' => $user->id,
        ]);

        Billing::chargeWithMethod($payment, $method);

        $this->assertSame([], RecordingGateway::$receivedChargePaymentMethodOptions->receiptItems);
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

class RecordingGateway implements PaymentGatewayContract, \Fomvasss\Billing\Contracts\TokenizesPaymentMethod
{
    public static ?ChargeOptions $receivedOptions = null;

    public static ?ChargeOptions $receivedChargePaymentMethodOptions = null;

    public function __construct(protected array $credentials, protected string $gatewayName) {}

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        self::$receivedOptions = $options;

        return new PaymentResult(url: 'https://example.test/pay');
    }

    public function createCustomer(Model&\Fomvasss\Billing\Contracts\Billable $billable): string
    {
        return (string) $billable->getKey();
    }

    public function attachPaymentMethod(Model&\Fomvasss\Billing\Contracts\Billable $billable, array $token): \Fomvasss\Billing\Models\PaymentMethod
    {
        throw new \RuntimeException('not used by this test');
    }

    public function chargePaymentMethod(Payment $payment, \Fomvasss\Billing\Models\PaymentMethod $method, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        self::$receivedChargePaymentMethodOptions = $options;

        return new PaymentResult(externalId: 'rec_1');
    }

    public function detachPaymentMethod(\Fomvasss\Billing\Models\PaymentMethod $method): void
    {
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        return new WebhookResult(type: \Fomvasss\Billing\Enums\WebhookEventType::Ignored, status: 'ignored');
    }

    public static function requiresDashboardWebhook(): bool
    {
        return false;
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
