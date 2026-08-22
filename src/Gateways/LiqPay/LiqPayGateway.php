<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\LiqPay;

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
use Illuminate\Support\Facades\Http;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * https://www.liqpay.ua/api/request (server-server) + https://www.liqpay.ua/api/3/checkout
 * (client-server form). Verified against the official SDKs (github.com/liqpay/sdk-php,
 * sdk-python) — NOT dropshop's reference, which was pre-existing and un-reverified: the signature
 * algorithm is SHA1 (`base64_encode(sha1($private_key.$data.$private_key, true))`), confirmed
 * directly from the PHP SDK source, not assumed from memory (the doc page's own prose still says
 * sha3-256 as of this writing — every one of its own code samples, bash through go, uses sha1;
 * that inconsistency is the doc's bug, not ours — re-confirmed independently a second time).
 *
 * `amount` is DECIMAL major units (e.g. "100.00" UAH) — unlike Monobank/Stripe, LiqPay does not
 * use minor units. Our own `payments.amount` column is always minor units (package-wide design,
 * see "Money" in the plan) — this driver is the one doing the /100 conversion, not the caller.
 *
 * TokenizesPaymentMethod, async like Monobank but ONE delivery, not two: `recurringbytoken: '1'`
 * on charge() → `card_token` arrives in the SAME server_url callback as the payment status (not a
 * separate delivery) — verified against liqpay.ua/en/doc/api/internet_acquiring/token and
 * .../checkout (self-integration tab). handleWebhook() persists it inline when present. No
 * gateway-side token-revocation endpoint is documented for this flow (unlike Monobank's `DELETE
 * /wallet/card`) — detachPaymentMethod() is local-only.
 */
class LiqPayGateway extends AbstractGateway implements RefundsPayments, ChecksPaymentStatus, TokenizesPaymentMethod, \Fomvasss\Billing\Contracts\ChecksGatewayHealth
{
    protected const CHECKOUT_URL = 'https://www.liqpay.ua/api/3/checkout';

    protected const API_URL = 'https://www.liqpay.ua/api/request';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $params = array_filter([
            // Gateway-specific extras the package has no neutral shape for — LiqPay's rro_info
            // (fiscalization) is the canonical case. Merged first so the driver's own fields
            // below always win: raw can add what we don't set, never override amount/order_id.
            ...$options->raw,
            'version' => 3,
            'public_key' => $this->publicKey(),
            'action' => 'pay',
            'amount' => $this->formatAmount($payment->amount),
            'currency' => $payment->currency,
            'description' => $options->description ?? "Payment #{$payment->id}",
            'order_id' => (string) $payment->id,
            'result_url' => $this->successUrl($payment, $options),
            'server_url' => $this->webhookUrl($options),
            // LiqPay accepts "uk"/"en" only — a full locale ("uk-UA") or anything else is an error
            // on its side, so a wider ChargeOptions::$locale is narrowed here rather than rejected.
            'language' => $this->language($options->locale),
            'recurringbytoken' => $options->saveCard ? '1' : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $data = base64_encode(json_encode($params, JSON_THROW_ON_ERROR));

        return new PaymentResult(
            form: [
                'action' => self::CHECKOUT_URL,
                'fields' => ['data' => $data, 'signature' => $this->sign($data)],
            ],
            // LiqPay's signed form itself doesn't expire — this bounds OUR cached checkout-form
            // page (payment_url), same link_ttl_minutes convention as the other drivers.
            expiresAt: now()->addMinutes($this->linkTtlMinutes(60)),
        );
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        // WebhookController's LiqPaySignatureValidator already verified the signature before this
        // ran — the stored payload is the raw POST fields (['data' => ..., 'signature' => ...]),
        // still encoded.
        $decoded = json_decode(base64_decode($webhookCall->payload['data']), true, 512, JSON_THROW_ON_ERROR);

        // A callback for a payment this package didn't create is Ignored, not a failed job.
        $payment = $this->findPaymentByReference($decoded['order_id'] ?? null);

        if ($payment === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $decoded);
        }

        // 'reversed' — a refund/chargeback carried out on LiqPay's side. Recognized and reported,
        // but not turned into a refund row: which field of this callback carries the reversed sum
        // (and whether it's the single reversal or a running total) hasn't been verified against a
        // live account, and a wrong figure here is worse than a gap. See reportUnrecordedReversal().
        if (($decoded['status'] ?? null) === 'reversed') {
            $this->reportUnrecordedReversal($payment, $decoded);

            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $decoded);
        }

        $status = match ($decoded['status']) {
            'success', 'sandbox' => PaymentStatus::Paid,
            'failure', 'error' => PaymentStatus::Failed,
            default => null,
        };

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($decoded['amount']) ? (int) round((float) $decoded['amount'] * 100) : null,
            $decoded['currency'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $decoded);
        }

        // card_token rides along in the SAME callback as the payment status (unlike Monobank's
        // separate delivery) — only present when charge() ran with recurringbytoken (saveCard),
        // and only meaningful on a successful payment. Persisted as a side effect, dispatched
        // directly: this call's WebhookResult already carries the Payment outcome below, so there's
        // no second return value for WebhookResultDispatcher to fire PaymentMethodAttached from.
        if ($status === PaymentStatus::Paid && ! empty($decoded['card_token'])) {
            $this->attachFromWebhook($payment, $decoded);
        }

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $decoded);
        }

        // receiver_commission — what LiqPay withholds from the MERCHANT, decimal in the payment's
        // currency (sender_/agent_commission are someone else's cost, not ours).
        if (! $payment->transitionTo($status, [
            'external_id' => $decoded['payment_id'] ?? $decoded['transaction_id'] ?? null,
            ...$this->feeFrom($decoded['receiver_commission'] ?? null, decimal: true),
        ])) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $decoded);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: $status === PaymentStatus::Paid ? 'succeeded' : 'failed',
            payment: $payment,
            externalId: (string) ($decoded['payment_id'] ?? $decoded['order_id']),
            raw: $decoded,
        );
    }

    public function refund(Payment $payment, ?Money $amount = null): PaymentResult
    {
        $response = $this->api([
            'action' => 'refund',
            'order_id' => (string) $payment->id,
            'amount' => $amount !== null ? $this->formatAmount($amount->amount) : null,
        ], retries: 1);

        // LiqPay answers a refused refund with HTTP 200 and result=error (err_code/err_description
        // carry the reason). Without this check the caller would record a refund row for money that
        // never moved.
        if (($response['result'] ?? null) !== 'ok') {
            throw new BillingException('LiqPay: refund was refused: ' . json_encode($response));
        }

        return new PaymentResult(externalId: $payment->external_id, raw: $response);
    }

    /** No gateway-side "customer" object — same reasoning as Monobank's walletId, ours to pick and stable per billable. */
    public function createCustomer(Model&Billable $billable): string
    {
        return $this->customerId($billable::class, (string) $billable->getKey());
    }

    /**
     * The uncommon path — see class docblock. $token = ['card_token' => '...'], already obtained
     * some other way than this driver's own webhook auto-attach (attachFromWebhook()). No API call
     * to verify it against — LiqPay doesn't expose a "look up this token" endpoint the way
     * Monobank's `GET /wallet` does, so this trusts the caller.
     */
    public function attachPaymentMethod(Model&Billable $billable, array $token): PaymentMethod
    {
        $cardToken = $token['card_token'] ?? throw new BillingException('LiqPay: token must include "card_token".');

        $method = $this->persistPaymentMethod(
            $billable::class,
            (string) $billable->getKey(),
            $billable->tenantId(),
            $this->customerId($billable::class, (string) $billable->getKey()),
            $cardToken,
        );

        PaymentMethodAttached::dispatch($method);

        return $method;
    }

    /**
     * action=paytoken — the outcome still resolves through handleWebhook() same as any other
     * Payment; this only initiates it. `ip` is documented Required for paytoken (fraud-prevention,
     * since no card is re-entered) — there's no live request in a scheduled/background charge, so
     * pass $options['ip'] when you track the customer's last known IP; falls back to a placeholder
     * otherwise (LiqPay accepts it, this is a known, documented limitation of off-session use).
     */
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        // No receiptItems here — LiqPay has no neutral fiscal-basket field, only rro_info via
        // $options->raw (same as charge(), see its docblock).
        $response = $this->api([
            ...$options->raw,
            'action' => 'paytoken',
            'card_token' => $method->external_id,
            'amount' => $this->formatAmount($payment->amount),
            'currency' => $payment->currency,
            'description' => $options->description ?? "Payment #{$payment->id}",
            'order_id' => (string) $payment->id,
            'ip' => $options->raw['ip'] ?? '127.0.0.1',
            'is_recurring' => true,
            'server_url' => $this->webhookUrl($options),
        ], retries: 1);

        return new PaymentResult(externalId: (string) ($response['payment_id'] ?? null), raw: $response);
    }

    /** No gateway-side revocation endpoint documented for a standalone card_token — local-only. */
    public function detachPaymentMethod(PaymentMethod $method): void
    {
        $method->delete();

        PaymentMethodDetached::dispatch($method);
    }

    public function checkStatus(Payment $payment): WebhookResult
    {
        $data = $this->api(['action' => 'status', 'order_id' => (string) $payment->id]);

        $status = match ($data['status'] ?? null) {
            'success', 'sandbox' => PaymentStatus::Paid,
            'failure', 'error' => PaymentStatus::Failed,
            'expired' => PaymentStatus::Canceled,
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : null,
            $data['currency'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        $externalId = (string) ($data['payment_id'] ?? $data['order_id']);

        if (! $payment->transitionTo($status, [
            'external_id' => $externalId,
            ...$this->feeFrom($data['receiver_commission'] ?? null, decimal: true),
        ])) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: match ($status) {
                PaymentStatus::Paid => 'succeeded',
                PaymentStatus::Failed => 'failed',
                default => 'canceled',
            },
            payment: $payment,
            externalId: $externalId,
            raw: $data,
        );
    }

    /**
     * No introspection endpoint — probes with the status of a nonexistent order. Live-verified
     * discriminators: err_code `payment_not_found` = credentials/signature fine (the request was
     * accepted and understood); `invalid_signature` (or any other auth-shaped error) = they aren't.
     */
    public function healthCheck(): \Fomvasss\Billing\DTO\GatewayHealth
    {
        return $this->probeHealth(function () {
            $data = $this->api(['action' => 'status', 'order_id' => 'billing-health-probe']);

            if (($data['err_code'] ?? null) === 'payment_not_found') {
                return 'credentials accepted';
            }

            throw new BillingException($data['err_description'] ?? $data['err_code'] ?? 'unexpected response: ' . json_encode($data));
        });
    }

    public static function label(): string
    {
        return 'LiqPay';
    }

    public static function credentialFields(): array
    {
        return [
            ['name' => 'public_key', 'type' => 'text', 'secret' => false, 'help' => 'Публічний ключ з кабінету LiqPay'],
            ['name' => 'private_key', 'type' => 'text', 'secret' => true, 'help' => 'Приватний ключ з кабінету LiqPay'],
            ['name' => 'link_ttl_minutes', 'type' => 'number', 'secret' => false, 'help' => 'TTL нашої checkout-form сторінки (payment_url), хв, дефолт 60'],
        ];
    }

    public static function supportedCurrencies(): array
    {
        return ['UAH', 'USD', 'EUR'];
    }

    /**
     * $retries = 1 (no retry) for anything that moves money: Laravel retries a ConnectionException
     * too, and a timeout says nothing about whether LiqPay already debited the card — without a
     * gateway-side idempotency key a second attempt is a second debit.
     */
    protected function api(array $params, int $retries = 2): array
    {
        $params = array_filter(['version' => 3, 'public_key' => $this->publicKey(), ...$params], static fn ($v) => $v !== null);

        $data = base64_encode(json_encode($params, JSON_THROW_ON_ERROR));

        return Http::asForm()
            ->timeout(15)
            ->retry($retries, 200)
            ->post(self::API_URL, ['data' => $data, 'signature' => $this->sign($data)])
            ->throw()
            ->json();
    }

    protected function language(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        return str_starts_with(strtolower($locale), 'uk') ? 'uk' : 'en';
    }

    /** base64_encode(sha1(private_key . data . private_key, true)) — confirmed from the official PHP SDK, not sha3 as an earlier (unreliable) doc fetch suggested. */
    protected function sign(string $data): string
    {
        return base64_encode(sha1($this->privateKey() . $data . $this->privateKey(), true));
    }

    protected function formatAmount(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }

    /**
     * The auto-attach path handleWebhook() calls when a paid transaction's callback carries a
     * card_token — no attachPaymentMethod() call needed for the common "charge once with
     * recurringbytoken, tokenize as a side effect" flow. Not routed through WebhookResultDispatcher
     * (this webhook call's WebhookResult already reports the Payment outcome), so dispatched here
     * directly instead.
     */
    protected function attachFromWebhook(Payment $payment, array $decoded): void
    {
        $method = $this->persistPaymentMethod(
            $payment->billable_type,
            (string) $payment->billable_id,
            $payment->billable instanceof Billable ? $payment->billable->tenantId() : null,
            $this->customerId($payment->billable_type, (string) $payment->billable_id),
            $decoded['card_token'],
            isset($decoded['sender_card_mask2']) ? substr($decoded['sender_card_mask2'], -4) : null,
            $decoded['sender_card_type'] ?? null,
        );

        // Direct dispatch runs BEFORE ProcessWebhookJob's dedup claim, so a re-delivered callback
        // would fire it again — wasRecentlyCreated is the dedup here: only a token not yet
        // persisted counts as "attached".
        if ($method->wasRecentlyCreated) {
            PaymentMethodAttached::dispatch($method);
        }
    }

    protected function customerId(string $billableType, string $billableId): string
    {
        return md5($billableType . ':' . $billableId);
    }

    protected function publicKey(): string
    {
        return $this->credentials['public_key'] ?? throw new BillingException('LiqPay: credential "public_key" is missing.');
    }

    protected function privateKey(): string
    {
        return $this->credentials['private_key'] ?? throw new BillingException('LiqPay: credential "private_key" is missing.');
    }
}
