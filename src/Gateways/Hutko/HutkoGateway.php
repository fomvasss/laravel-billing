<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Hutko;

use Fomvasss\Billing\Contracts\Billable;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * https://pay.hutko.org/api/ — no dropshop reference existed for this one (see the package plan's
 * "Hutko" section); verified instead against the official WooCommerce plugin source
 * (github.com/hutko-service/hutko-payment, class-wc-oplata-api.php +
 * abstract-wc-oplata-payment-gateway.php) for charge()/handleWebhook()/sign() (docs.hutko.org
 * 404s on a direct fetch, no JS rendering) and, for TokenizesPaymentMethod specifically, a
 * follow-up `r.jina.ai`-rendered fetch of docs.hutko.org/docs/page/3 (shared response schema,
 * `rectoken`) and /page/10 ("Покупка по токену картки", the `/api/recurring` request schema).
 *
 * Uses Scheme B (`checkout/url`, host-to-host — merchant gets a checkout_url back), not Scheme A's
 * auto-submit redirect form, for consistency with Monobank/Stripe's PaymentResult::$url pattern.
 *
 * `amount` — minor units, confirmed from the plugin (`(int) round($order->get_total() * 100)`).
 *
 * TokenizesPaymentMethod: like WayForPay, no opt-in flag — `rectoken` is part of the SAME shared
 * response schema every approved payment already returns (`docs.hutko.org/docs/page/3`'s
 * "Параметри фінальної відповіді"), so handleWebhook() persists it unconditionally whenever
 * present. No gateway-side token-revocation endpoint is documented — detachPaymentMethod() is
 * local-only, same reasoning as LiqPay/WayForPay.
 */
class HutkoGateway extends AbstractGateway implements TokenizesPaymentMethod
{
    protected const BASE_URL = 'https://pay.hutko.org/api/';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $data = $this->request('checkout/url', array_filter([
            // Gateway-specific extras. Merged first so the driver's own fields below always win —
            // raw adds what we don't set, never overrides amount/order_id.
            ...$options->raw,
            'order_id' => (string) $payment->id,
            'order_desc' => $options->description ?? "Payment #{$payment->id}",
            'amount' => $payment->amount,
            'currency' => $payment->currency_code,
            'lang' => $options->locale,
            'sender_email' => $options->customerEmail,
            'response_url' => $this->successUrl($options),
            'server_callback_url' => $this->webhookUrl($options),
        ]));

        return new PaymentResult(url: $data['checkout_url']);
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $payload = $webhookCall->payload;

        // Reversals/refunds surface here too (reversal_amount present, or tran_type=reverse) — the
        // plugin ignores these silently rather than mutating the original order; same "explicit
        // refund is the supported path" reasoning as the other built-in drivers.
        if (! empty($payload['reversal_amount']) || ($payload['tran_type'] ?? null) === 'reverse') {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        $payment = Payment::findOrFail($payload['order_id']);

        $status = match ($payload['order_status'] ?? null) {
            'approved' => PaymentStatus::Paid,
            'declined' => PaymentStatus::Failed,
            'expired' => PaymentStatus::Canceled,
            // created/processing — not terminal
            default => null,
        };

        // rectoken rides along in the SAME callback as the payment status, automatically on any
        // approved card payment (no opt-in flag — see class docblock). Persisted as a side effect,
        // dispatched directly: this call's WebhookResult already carries the Payment outcome below,
        // so there's no second return value for WebhookResultDispatcher to fire
        // PaymentMethodAttached from.
        if ($status === PaymentStatus::Paid && ! empty($payload['rectoken'])) {
            $this->attachFromWebhook($payment, $payload);
        }

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        $payment->update(['status' => $status]);

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: match ($status) {
                PaymentStatus::Paid => 'succeeded',
                PaymentStatus::Failed => 'failed',
                default => 'canceled',
            },
            payment: $payment,
            externalId: (string) $payload['order_id'],
            raw: $payload,
        );
    }

    /** No gateway-side "customer" object — same reasoning as Monobank/LiqPay/WayForPay, ours to pick and stable per billable. */
    public function createCustomer(Model&Billable $billable): string
    {
        return $this->customerId($billable::class, (string) $billable->getKey());
    }

    /**
     * The uncommon path — see class docblock. $token = ['rectoken' => '...'], already obtained some
     * other way than this driver's own webhook auto-attach (attachFromWebhook()). No API call to
     * verify it against — not documented for Hutko, same limitation as LiqPay/WayForPay's.
     */
    public function attachPaymentMethod(Model&Billable $billable, array $token): PaymentMethod
    {
        $rectoken = $token['rectoken'] ?? throw new BillingException('Hutko: token must include "rectoken".');

        $method = $this->persistPaymentMethod(
            $billable::class,
            (string) $billable->getKey(),
            $billable->tenantId(),
            $this->customerId($billable::class, (string) $billable->getKey()),
            $rectoken,
        );

        PaymentMethodAttached::dispatch($method);

        return $method;
    }

    /**
     * POST /api/recurring — "Покупка по токену картки". Doesn't go through request()'s
     * response_status==="success" gate: a decline is a normal business outcome to let the caller
     * (and, ultimately, handleWebhook()) resolve, not a wiring failure to throw on, same reasoning
     * as Stripe's card_error handling. The outcome still resolves through handleWebhook() same as
     * any other Payment; this only initiates it.
     */
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, array $options = []): PaymentResult
    {
        $fields = array_filter([
            'order_id' => (string) $payment->id,
            'order_desc' => $options['description'] ?? "Payment #{$payment->id}",
            'amount' => $payment->amount,
            'currency' => $payment->currency_code,
            'rectoken' => $method->external_id,
            'server_callback_url' => route('billing.webhook', ['gateway' => $this->gatewayName]),
            'client_ip' => $options['ip'] ?? '127.0.0.1',
        ]);

        $fields['merchant_id'] = $this->merchantId();
        $fields['signature'] = $this->sign($fields);

        $data = Http::baseUrl(self::BASE_URL)
            ->timeout(15)
            ->retry(2, 200)
            ->post('recurring', ['request' => $fields])
            ->throw()
            ->json('response');

        return new PaymentResult(externalId: isset($data['payment_id']) ? (string) $data['payment_id'] : null, raw: $data);
    }

    /** No gateway-side revocation endpoint documented for a standalone rectoken — local-only. */
    public function detachPaymentMethod(PaymentMethod $method): void
    {
        $method->delete();

        PaymentMethodDetached::dispatch($method);
    }

    public static function label(): string
    {
        return 'Hutko';
    }

    public static function credentialFields(): array
    {
        return [
            ['name' => 'merchant_id', 'type' => 'text', 'secret' => false, 'help' => 'merchant_id з мерчант-порталу Hutko'],
            ['name' => 'secret_key', 'type' => 'text', 'secret' => true, 'help' => 'Секретний ключ для підпису запитів'],
        ];
    }

    public static function supportedCurrencies(): array
    {
        // Hutko converts multi-currency payments to UAH on its own side (self-converts, unlike
        // Monobank which requires the merchant to already be in UAH) — see the package plan.
        return ['UAH', 'USD', 'EUR', 'PLN', 'CZK', 'GBP'];
    }

    /** {"request": {merchant_id, signature, ...fields}} in, {"response": {response_status, ...}} out — confirmed from WC_Oplata_API::sendToAPI(). */
    protected function request(string $endpoint, array $fields): array
    {
        $fields['merchant_id'] = $this->merchantId();
        $fields['signature'] = $this->sign($fields);

        $response = Http::baseUrl(self::BASE_URL)
            ->timeout(15)
            ->retry(2, 200)
            ->post($endpoint, ['request' => $fields])
            ->throw()
            ->json('response');

        if (($response['response_status'] ?? null) !== 'success') {
            throw new BillingException('Hutko: request to "' . $endpoint . '" was not successful: ' . json_encode($response));
        }

        return $response;
    }

    /** ksort the fields, prepend the secret key, pipe-join, SHA1 — confirmed verbatim from WC_Oplata_API::getSignature(). */
    protected function sign(array $fields): string
    {
        $fields = array_filter($fields, static fn ($value) => $value !== '' && $value !== null);
        ksort($fields);

        $string = $this->secretKey();

        foreach ($fields as $value) {
            $string .= '|' . $value;
        }

        return sha1($string);
    }

    protected function merchantId(): string
    {
        return $this->credentials['merchant_id'] ?? throw new BillingException('Hutko: credential "merchant_id" is missing.');
    }

    protected function secretKey(): string
    {
        return $this->credentials['secret_key'] ?? throw new BillingException('Hutko: credential "secret_key" is missing.');
    }

    /**
     * The auto-attach path handleWebhook() calls whenever an approved payment's callback carries a
     * rectoken — no attachPaymentMethod() call needed. Not routed through WebhookResultDispatcher
     * (this webhook call's WebhookResult already reports the Payment outcome), so dispatched here
     * directly instead.
     */
    protected function attachFromWebhook(Payment $payment, array $payload): void
    {
        $method = $this->persistPaymentMethod(
            $payment->billable_type,
            (string) $payment->billable_id,
            $payment->billable instanceof Billable ? $payment->billable->tenantId() : null,
            $this->customerId($payment->billable_type, (string) $payment->billable_id),
            $payload['rectoken'],
            isset($payload['masked_card']) ? substr($payload['masked_card'], -4) : null,
            $payload['card_type'] ?? null,
        );

        PaymentMethodAttached::dispatch($method);
    }

    protected function customerId(string $billableType, string $billableId): string
    {
        return md5($billableType . ':' . $billableId);
    }
}
