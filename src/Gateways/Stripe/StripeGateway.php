<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Stripe;

use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\Contracts\RefundsPayments;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Gateways\AbstractGateway;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * https://api.stripe.com/v1 — Checkout Sessions (mode=payment), verified against the official docs
 * (docs.stripe.com/api/checkout/sessions/create, docs.stripe.com/webhooks/signatures).
 *
 * `amount` — minor units (cents), same convention our own Payment.amount already uses, no
 * conversion needed (unlike LiqPay/WayForPay).
 *
 * metadata.payment_id is set on BOTH the Checkout Session and the resulting PaymentIntent
 * (payment_intent_data.metadata) — Stripe doesn't propagate Session metadata to the PaymentIntent
 * automatically, and both checkout.session.* and payment_intent.* events need it to look our
 * Payment row back up.
 */
class StripeGateway extends AbstractGateway implements RefundsPayments, ChecksPaymentStatus
{
    protected const BASE_URL = 'https://api.stripe.com/v1';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $response = $this->http()->asForm()->post('/checkout/sessions', array_filter([
            'mode' => 'payment',
            'line_items' => $this->lineItems($payment, $options),
            'success_url' => $this->successUrl($options),
            'cancel_url' => $this->failUrl($options),
            'customer_email' => $options->customerEmail,
            'client_reference_id' => (string) $payment->id,
            'metadata' => ['payment_id' => (string) $payment->id],
            'payment_intent_data' => ['metadata' => ['payment_id' => (string) $payment->id]],
            'locale' => $options->locale,
        ]))->throw();

        $data = $response->json();

        return new PaymentResult(
            url: $data['url'],
            expiresAt: isset($data['expires_at']) ? Carbon::createFromTimestamp($data['expires_at']) : null,
            externalId: $data['id'],
        );
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $event = $webhookCall->payload;
        $object = $event['data']['object'] ?? [];

        $paymentId = $object['metadata']['payment_id'] ?? $object['client_reference_id'] ?? null;

        if ($paymentId === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        $status = match ($event['type'] ?? null) {
            'checkout.session.completed' => ($object['payment_status'] ?? null) === 'paid' ? PaymentStatus::Paid : null,
            'checkout.session.expired' => PaymentStatus::Canceled,
            'payment_intent.payment_failed' => PaymentStatus::Failed,
            // charge.refunded etc. — same "explicit refund() is the supported path, webhook-driven
            // refund tracking is a later nuance" reasoning as the other built-in drivers
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        $payment = Payment::findOrFail($paymentId);

        $externalId = $object['payment_intent'] ?? $object['id'] ?? null;

        $payment->update(array_filter([
            'status' => $status,
            'external_id' => $externalId,
        ]));

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: match ($status) {
                PaymentStatus::Paid => 'succeeded',
                PaymentStatus::Failed => 'failed',
                default => 'canceled',
            },
            payment: $payment,
            externalId: $externalId ?? (string) $payment->id,
            raw: $event,
        );
    }

    public function refund(Payment $payment, ?Money $amount = null): PaymentResult
    {
        $response = $this->http()->asForm()->post('/refunds', array_filter([
            'payment_intent' => $payment->external_id,
            'amount' => $amount?->amount,
        ]))->throw();

        return new PaymentResult(externalId: $payment->external_id, raw: $response->json());
    }

    public function checkStatus(Payment $payment): WebhookResult
    {
        $data = $this->http()->get("/checkout/sessions/{$payment->external_id}")->throw()->json();

        $status = match (true) {
            $data['status'] === 'complete' && ($data['payment_status'] ?? null) === 'paid' => PaymentStatus::Paid,
            $data['status'] === 'expired' => PaymentStatus::Canceled,
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        $externalId = $data['payment_intent'] ?? $data['id'];
        $payment->update(['status' => $status, 'external_id' => $externalId]);

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: $status === PaymentStatus::Paid ? 'succeeded' : 'canceled',
            payment: $payment,
            externalId: $externalId,
            raw: $data,
        );
    }

    public static function label(): string
    {
        return 'Stripe';
    }

    public static function credentialFields(): array
    {
        return [
            ['name' => 'secret_key', 'type' => 'text', 'secret' => true, 'help' => 'Secret key (sk_...) з дашборду Stripe'],
            ['name' => 'webhook_secret', 'type' => 'text', 'secret' => true, 'help' => 'Signing secret (whsec_...) вебхук-ендпоінта'],
        ];
    }

    public static function supportedCurrencies(): array
    {
        // Stripe supports 135+ currencies — this is a representative subset, not the exhaustive
        // list; extend as real Price rows in other currencies actually come up (UAH is NOT
        // supported by Stripe, unlike the other 4 built-in drivers).
        return ['USD', 'EUR', 'GBP', 'PLN', 'CZK', 'CAD', 'AUD'];
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->secretKey())
            ->timeout(15)
            ->retry(2, 200);
    }

    protected function lineItems(Payment $payment, ChargeOptions $options): array
    {
        if ($options->receiptItems === []) {
            return [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payment->currency_code),
                    'unit_amount' => $payment->amount,
                    'product_data' => ['name' => $options->description ?? "Payment #{$payment->id}"],
                ],
            ]];
        }

        return array_map(fn (array $item) => [
            'quantity' => $item['qty'],
            'price_data' => [
                'currency' => strtolower($payment->currency_code),
                'unit_amount' => $item['unitAmount'],
                'product_data' => ['name' => $item['name']],
            ],
        ], $options->receiptItems);
    }

    protected function secretKey(): string
    {
        return $this->credentials['secret_key'] ?? throw new BillingException('Stripe: credential "secret_key" is missing.');
    }
}
