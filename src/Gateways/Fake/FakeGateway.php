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
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * No real bank involved — for local dev/CI (see "Sandbox / тестовий режим" in the package plan).
 * charge() sends the browser to a local page with two buttons; clicking one POSTs straight to this
 * driver's own registered webhook URL, so the full real pipeline runs (WebhookCall row, queued
 * ProcessWebhookJob, events) instead of a shortcut that only tests half of it.
 */
class FakeGateway extends AbstractGateway
{
    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        return new PaymentResult(
            url: route('billing.fake.show', $payment),
        );
    }

    public function handleWebhook(WebhookCall $webhookCall): WebhookResult
    {
        $payload = $webhookCall->payload;

        $payment = Payment::findOrFail($payload['payment_id']);

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
