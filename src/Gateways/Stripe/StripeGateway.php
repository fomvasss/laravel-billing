<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Stripe;

use Fomvasss\Billing\Contracts\Billable;
use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\Contracts\RefundsPayments;
use Fomvasss\Billing\Contracts\TokenizesPaymentMethod;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Events\PaymentMethodAttached;
use Fomvasss\Billing\Events\PaymentMethodDetached;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Gateways\AbstractGateway;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * https://api.stripe.com/v1 — Checkout Sessions (mode=payment) for the redirect flow,
 * PaymentIntents + PaymentMethods for saved-card/off-session charges — verified against the
 * official docs (docs.stripe.com/api/checkout/sessions/create, docs.stripe.com/api/payment_intents/create,
 * docs.stripe.com/api/payment_methods/attach, docs.stripe.com/webhooks/signatures).
 *
 * `amount` — minor units (cents), same convention our own Payment.amount already uses, no
 * conversion needed (unlike LiqPay/WayForPay).
 *
 * metadata.payment_id is set on the Checkout Session, its PaymentIntent (payment_intent_data.metadata
 * — Stripe doesn't propagate Session metadata to the PaymentIntent automatically), and on
 * chargePaymentMethod()'s own PaymentIntent — checkout.session.*, payment_intent.succeeded and
 * payment_intent.payment_failed all need it to look our Payment row back up.
 */
class StripeGateway extends AbstractGateway implements RefundsPayments, ChecksPaymentStatus, TokenizesPaymentMethod
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
            // A raw off-session PaymentIntent (chargePaymentMethod()) never goes through Checkout,
            // so it has no checkout.session.* counterpart — payment_intent.succeeded is the only
            // signal for it. Also fires alongside checkout.session.completed for the redirect flow
            // (Payment::update() below is idempotent, so processing both is harmless).
            'payment_intent.succeeded' => PaymentStatus::Paid,
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

    public function createCustomer(Model&Billable $billable): string
    {
        $data = $this->http()->asForm()->post('/customers', [
            'metadata' => [
                'billable_type' => $billable::class,
                'billable_id' => (string) $billable->getKey(),
            ],
        ])->throw()->json();

        return $data['id'];
    }

    /** $token = ['payment_method_id' => 'pm_...'] — from Stripe.js/Elements confirming a SetupIntent on the frontend. */
    public function attachPaymentMethod(Model&Billable $billable, array $token): PaymentMethod
    {
        $paymentMethodId = $token['payment_method_id']
            ?? throw new BillingException('Stripe: token must include "payment_method_id" (pm_...).');

        $customerId = $this->resolveCustomerId($billable);

        $data = $this->http()->asForm()
            ->post("/payment_methods/{$paymentMethodId}/attach", ['customer' => $customerId])
            ->throw()
            ->json();

        // Not required for the charge itself, but keeps Stripe's own "default payment method for
        // this customer" in sync with ours (relevant if this customer is ever charged via the
        // Stripe Dashboard or Invoicing directly, outside this package).
        $this->http()->asForm()
            ->post("/customers/{$customerId}", ['invoice_settings' => ['default_payment_method' => $paymentMethodId]])
            ->throw();

        PaymentMethod::query()
            ->where('billable_type', $billable::class)
            ->where('billable_id', $billable->getKey())
            ->where('gateway', $this->gatewayName)
            ->update(['is_default' => false]);

        $method = PaymentMethod::updateOrCreate(
            [
                'gateway' => $this->gatewayName,
                'external_customer_id' => $customerId,
                'external_id' => $paymentMethodId,
            ],
            [
                'type' => 'card',
                'brand' => $data['card']['brand'] ?? null,
                'last4' => $data['card']['last4'] ?? null,
                'expires_at' => isset($data['card']['exp_year'], $data['card']['exp_month'])
                    ? Carbon::createFromDate((int) $data['card']['exp_year'], (int) $data['card']['exp_month'], 1)->endOfMonth()
                    : null,
                'is_default' => true,
                'tenant_id' => $billable->tenantId(),
                'billable_type' => $billable::class,
                'billable_id' => $billable->getKey(),
            ],
        );

        PaymentMethodAttached::dispatch($method);

        return $method;
    }

    /**
     * Off-session charge against an already-attached PaymentMethod — the actual outcome
     * (succeeded/requires 3DS/declined) still arrives through handleWebhook()
     * (payment_intent.succeeded/payment_intent.payment_failed), same as every other Payment; this
     * call only initiates it. A card-error response (decline, authentication_required, ...) is a
     * normal business outcome, not a wiring failure, so it's returned rather than thrown — anything
     * else (bad credentials, malformed request) still throws.
     */
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, array $options = []): PaymentResult
    {
        // retry(1) overrides http()'s default retry(2, 200) — a card decline is a normal business
        // outcome to inspect below, not a transient failure worth retrying (and retrying a charge
        // attempt at all is a bad idea without an idempotency key, which this call doesn't send).
        $response = $this->http()->retry(1)->asForm()->post('/payment_intents', array_filter([
            'amount' => $payment->amount,
            'currency' => strtolower($payment->currency_code),
            'customer' => $method->external_customer_id,
            'payment_method' => $method->external_id,
            'off_session' => true,
            'confirm' => true,
            'metadata' => ['payment_id' => (string) $payment->id],
        ]));

        $data = $response->json();

        if ($response->failed() && ($data['error']['type'] ?? null) !== 'card_error') {
            $response->throw();
        }

        $externalId = $data['id'] ?? $data['error']['payment_intent']['id'] ?? null;

        return new PaymentResult(externalId: $externalId, raw: $data);
    }

    public function detachPaymentMethod(PaymentMethod $method): void
    {
        $this->http()->asForm()->post("/payment_methods/{$method->external_id}/detach")->throw();

        $method->delete();

        PaymentMethodDetached::dispatch($method);
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

    /** Reuses the customer id from a previously attached method for this billable+gateway, creates one otherwise. */
    protected function resolveCustomerId(Model&Billable $billable): string
    {
        $existing = PaymentMethod::query()
            ->where('billable_type', $billable::class)
            ->where('billable_id', $billable->getKey())
            ->where('gateway', $this->gatewayName)
            ->whereNotNull('external_customer_id')
            ->value('external_customer_id');

        return $existing ?? $this->createCustomer($billable);
    }
}
