<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * A callback whose reference is NOT one of our UUIDs (another integration on the same merchant
 * account posting its own order ids) must resolve to Ignored on every database — Postgres throws
 * casting a non-UUID string to the uuid PK column where MySQL/SQLite just find nothing, which is
 * why drivers look payments up through AbstractGateway::findPaymentByReference().
 */
class ForeignWebhookReferenceTest extends TestCase
{
    public function test_non_uuid_references_are_ignored_on_every_driver(): void
    {
        $payloads = [
            'monobank' => ['reference' => 'shop-order-123', 'invoiceId' => 'inv_1', 'status' => 'success', 'amount' => 100, 'ccy' => 980],
            'liqpay' => ['data' => base64_encode(json_encode(['order_id' => 'shop-order-123', 'status' => 'success', 'payment_id' => 5]))],
            'wayforpay' => ['orderReference' => 'shop-order-123', 'transactionStatus' => 'Approved', 'amount' => 1, 'currency' => 'UAH'],
            'hutko' => ['order_id' => 'shop-order-123', 'order_status' => 'approved', 'amount' => '100', 'currency' => 'UAH'],
            'fake' => ['payment_id' => 'shop-order-123', 'status' => 'paid'],
        ];

        foreach ($payloads as $gateway => $payload) {
            $result = Billing::driver($gateway)->handleWebhook(new BillingWebhookCall(['name' => $gateway, 'payload' => $payload]));

            $this->assertSame(WebhookEventType::Ignored, $result->type, "Driver [{$gateway}] must ignore a foreign reference.");
        }
    }
}
