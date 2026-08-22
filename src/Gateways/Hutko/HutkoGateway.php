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
 * TokenizesPaymentMethod: OPT-IN like Monobank/LiqPay, not automatic like WayForPay —
 * `required_rectoken: 'Y'` must be sent on charge() (ChargeOptions::$saveCard) or the callback's
 * `rectoken` field arrives empty. The response schema lists the field on every approved payment,
 * which misled the original research; the live test merchant settled it. handleWebhook() persists
 * it whenever non-empty. No gateway-side token-revocation endpoint is documented —
 * detachPaymentMethod() is local-only, same reasoning as LiqPay/WayForPay.
 */
class HutkoGateway extends AbstractGateway implements TokenizesPaymentMethod, \Fomvasss\Billing\Contracts\ChecksPaymentStatus, \Fomvasss\Billing\Contracts\ChecksGatewayHealth
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
            'currency' => $payment->currency,
            'lang' => $options->locale,
            'sender_email' => $options->customerEmail,
            'response_url' => $this->successUrl($payment, $options),
            'server_callback_url' => $this->webhookUrl($options),
            'reservation_data' => $this->reservationData($options->receiptItems),
            // Without this the callback's rectoken field arrives EMPTY — confirmed on the live
            // test merchant and in the official plugin (required_rectoken='Y'). The response
            // schema always lists the field, which misled the original research.
            'required_rectoken' => $options->saveCard ? 'Y' : null,
            // Explicit checkout TTL (seconds) — the docs' `expired` order_status is defined by this
            // very parameter, so payment_url_expires_at below mirrors the real gateway-side limit.
            'lifetime' => $this->linkTtlMinutes() * 60,
        ]));

        return new PaymentResult(url: $data['checkout_url'], expiresAt: now()->addMinutes($this->linkTtlMinutes()), raw: $data);
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $payload = $webhookCall->payload;

        // A callback for a payment this package didn't create is Ignored, not a failed job.
        $payment = $this->findPaymentByReference($payload['order_id'] ?? null);

        if ($payment === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        // A reversal, ours or one issued from Hutko's dashboard: reversal_amount is the order's
        // running reversed total (minor units, like every Hutko amount), so a re-delivery settles
        // instead of stacking.
        if (! empty($payload['reversal_amount']) || ($payload['tran_type'] ?? null) === 'reverse') {
            return $this->recordRefundFromWebhook($payment, $payload);
        }

        $status = match ($payload['order_status'] ?? null) {
            'approved' => PaymentStatus::Paid,
            'declined' => PaymentStatus::Failed,
            'expired' => PaymentStatus::Canceled,
            // created/processing — not terminal
            default => null,
        };

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($payload['amount']) ? (int) $payload['amount'] : null, // minor units, same as the request's own `amount`
            $payload['currency'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

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

        // payment_id — Hutko's own transaction id; persisted so the row is findable by the
        // gateway reference (support lookups), and used as the dedup identity: a declined-then-
        // retried checkout gets a fresh payment_id, so both outcomes dispatch.
        $externalId = isset($payload['payment_id']) ? (string) $payload['payment_id'] : null;

        // fee — Hutko's commission, minor units like every Hutko amount (the test merchant sends
        // ""); spread AFTER the array_filter so a real fee=0 isn't dropped by it.
        if (! $payment->transitionTo($status, [
            ...array_filter(['external_id' => $externalId]),
            ...$this->feeFrom($payload['fee'] ?? null),
        ])) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: match ($status) {
                PaymentStatus::Paid => 'succeeded',
                PaymentStatus::Failed => 'failed',
                default => 'canceled',
            },
            payment: $payment,
            externalId: $externalId ?? (string) $payload['order_id'],
            raw: $payload,
        );
    }

    protected function recordRefundFromWebhook(Payment $charge, array $payload): WebhookResult
    {
        $cumulative = isset($payload['reversal_amount']) && $payload['reversal_amount'] !== ''
            ? (int) $payload['reversal_amount']
            : null;

        $refund = $this->recordExternalRefund(
            $charge,
            $cumulative,
            isset($payload['payment_id']) ? 'reverse-' . $payload['payment_id'] : null,
            $payload,
        );

        if ($refund === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: 'refunded',
            payment: $refund,
            externalId: $refund->external_id ?? "refund:{$charge->id}:" . ($cumulative ?? $refund->amount),
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
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $fields = array_filter([
            ...$options->raw,
            'order_id' => (string) $payment->id,
            'order_desc' => $options->description ?? "Payment #{$payment->id}",
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'rectoken' => $method->external_id,
            'server_callback_url' => $this->webhookUrl($options),
            'client_ip' => $options->raw['ip'] ?? '127.0.0.1',
            // Same key/shape as charge()'s checkout/url request — NOT independently live-verified
            // against /api/recurring's own schema (only checkout/url's fiscalization was, see the
            // class docblock). Verify against a real recurring charge before relying on it; drop
            // this key and pass receiptItems: [] if /api/recurring turns out to reject it.
            'reservation_data' => $this->reservationData($options->receiptItems),
        ]);

        $fields['merchant_id'] = $this->merchantId();
        $fields['signature'] = $this->sign($fields);

        // retry(1): Laravel retries a ConnectionException too, and a timeout says nothing about
        // whether Hutko already debited the card — a second attempt can be a second debit.
        $data = Http::baseUrl(self::BASE_URL)
            ->timeout(15)
            ->retry(1)
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

    /**
     * POST /api/status/order_id (yes, the literal path — live-verified; /api/status/order 404s
     * into an S3 error page). Same status vocabulary as the callback, so the mapping mirrors
     * handleWebhook(). Used by billing:reconcile-pending-payments — before this existed, a Hutko
     * payment with a lost webhook could only be written off as a dead checkout.
     */
    public function checkStatus(Payment $payment): WebhookResult
    {
        $fields = ['order_id' => (string) $payment->id];
        $fields['merchant_id'] = $this->merchantId();
        $fields['signature'] = $this->sign($fields);

        $data = Http::baseUrl(self::BASE_URL)
            ->timeout(15)
            ->retry(2, 200)
            ->post('status/order_id', ['request' => $fields])
            ->throw()
            ->json('response');

        $status = match ($data['order_status'] ?? null) {
            'approved' => PaymentStatus::Paid,
            'declined' => PaymentStatus::Failed,
            'expired' => PaymentStatus::Canceled,
            default => null, // created/processing, or an error response (e.g. 1018 order not found)
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data ?? []);
        }

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($data['amount']) ? (int) $data['amount'] : null,
            $data['currency'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        $externalId = isset($data['payment_id']) ? (string) $data['payment_id'] : null;

        if (! $payment->transitionTo($status, [
            ...array_filter(['external_id' => $externalId]),
            ...$this->feeFrom($data['fee'] ?? null),
        ])) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data ?? []);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: match ($status) {
                PaymentStatus::Paid => 'succeeded',
                PaymentStatus::Failed => 'failed',
                default => 'canceled',
            },
            payment: $payment,
            externalId: $externalId ?? (string) $payment->id,
            raw: $data,
        );
    }

    /**
     * No introspection endpoint — probes /api/status/order_id with a nonexistent order.
     * Live-verified discriminators: error_code 1018 "Order not found" = credentials/signature
     * fine; 1014 "Invalid signature" (or anything else) = they aren't.
     */
    public function healthCheck(): \Fomvasss\Billing\DTO\GatewayHealth
    {
        return $this->probeHealth(function () {
            $fields = ['order_id' => 'billing-health-probe'];
            $fields['merchant_id'] = $this->merchantId();
            $fields['signature'] = $this->sign($fields);

            $data = Http::baseUrl(self::BASE_URL)
                ->timeout(15)
                ->retry(1)
                ->post('status/order_id', ['request' => $fields])
                ->throw()
                ->json('response');

            if ((int) ($data['error_code'] ?? 0) === 1018) {
                return 'credentials accepted';
            }

            throw new BillingException(($data['error_message'] ?? 'unexpected response') . ' (error_code ' . ($data['error_code'] ?? '?') . ')');
        });
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
            ['name' => 'link_ttl_minutes', 'type' => 'number', 'secret' => false, 'help' => 'TTL посилання на оплату, хв (lifetime), дефолт 1440 (доба)'],
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

    /**
     * Fiscal basket for Hutko's programmable RRO (docs.hutko.org/uk/docs/page/50) — base64'd JSON,
     * not a plain array like every other field here. Omitted entirely when there are no receipt
     * items: Hutko then fiscalizes a single line for `amount` described by `order_desc`, which is
     * the correct fallback, not an error.
     *
     * `price`/`total_amount` are DECIMAL major units here, even though this same request's `amount`
     * is minor units — Hutko's own inconsistency, converted in one place so it can't leak out.
     * Whole values serialize without decimals (700.0 → `700`), which their docs explicitly allow
     * (`15` is listed as a valid price alongside `400.00`) — don't string-format them "to be safe".
     * `id` is just the line's position in the basket, not a catalog id (unlike LiqPay's rro_info),
     * so the package's neutral receiptItems shape maps over without anything extra from the merchant.
     */
    protected function reservationData(array $receiptItems): ?string
    {
        if ($receiptItems === []) {
            return null;
        }

        $products = [];

        foreach (array_values($receiptItems) as $index => $item) {
            $quantity = (float) $item['qty'];

            $products[] = [
                'id' => $index + 1,
                'name' => $item['name'],
                'price' => round($item['unitAmount'] / 100, 2),
                'total_amount' => round($item['unitAmount'] * $quantity / 100, 2),
                'quantity' => $quantity,
            ];
        }

        return base64_encode(json_encode(['products' => $products], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** ksort the fields, prepend the secret key, pipe-join, SHA1 — confirmed verbatim from WC_Oplata_API::getSignature(). */
    protected function sign(array $fields): string
    {
        $fields = array_filter($fields, static fn ($value) => $value !== '' && $value !== null);
        ksort($fields);

        $string = $this->secretKey();

        foreach ($fields as $value) {
            // json_encode for arrays, same as HutkoSignatureValidator — anything nested passed
            // through ChargeOptions::$raw would otherwise be "Array" in the signed string.
            $string .= '|' . (is_array($value) ? json_encode($value) : $value);
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

        // Direct dispatch runs BEFORE ProcessWebhookJob's dedup claim — wasRecentlyCreated keeps a
        // re-delivered callback from firing PaymentMethodAttached again (same as LiqPay/WayForPay).
        if ($method->wasRecentlyCreated) {
            PaymentMethodAttached::dispatch($method);
        }
    }

    protected function customerId(string $billableType, string $billableId): string
    {
        return md5($billableType . ':' . $billableId);
    }
}
