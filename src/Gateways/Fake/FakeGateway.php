<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Fake;

use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Gateways\AbstractGateway;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * No real bank involved — for local dev/CI (see "Sandbox / тестовий режим" in the package plan).
 * charge() sends the browser to a local page with two buttons; clicking one POSTs straight to this
 * driver's own registered webhook URL, so the full real pipeline runs (WebhookCall row, queued
 * ProcessWebhookJob, events) instead of a shortcut that only tests half of it.
 */
class FakeGateway extends AbstractGateway implements \Fomvasss\Billing\Contracts\ChecksGatewayHealth
{
    /** No bank behind it — always healthy; lets a settings UI / billing:health be exercised locally. */
    public function healthCheck(): \Fomvasss\Billing\DTO\GatewayHealth
    {
        return \Fomvasss\Billing\DTO\GatewayHealth::up('fake gateway, always up', 0.0);
    }

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        return new PaymentResult(
            url: route('billing.fake.show', $payment),
        );
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $payload = $webhookCall->payload;

        $payment = isset($payload['payment_id']) ? Payment::find($payload['payment_id']) : null;

        if ($payment === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        $succeeded = ($payload['result'] ?? null) === 'success';

        $payment->update(['status' => $succeeded ? PaymentStatus::Paid : PaymentStatus::Failed]);

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: $succeeded ? 'succeeded' : 'failed',
            payment: $payment,
            externalId: "fake-{$payment->id}",
            raw: $payload,
        );
    }

    public static function credentialFields(): array
    {
        return [];
    }

    public static function supportedCurrencies(): array
    {
        return ['UAH', 'USD', 'EUR'];
    }
}
