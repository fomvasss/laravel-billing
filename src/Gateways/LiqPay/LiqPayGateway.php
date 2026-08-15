<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\LiqPay;

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
use Illuminate\Support\Facades\Http;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * https://www.liqpay.ua/api/request (server-server) + https://www.liqpay.ua/api/3/checkout
 * (client-server form). Verified against the official SDKs (github.com/liqpay/sdk-php,
 * sdk-python) — NOT dropshop's reference, which was pre-existing and un-reverified: the signature
 * algorithm is SHA1 (`base64_encode(sha1($private_key.$data.$private_key, true))`), confirmed
 * directly from the PHP SDK source, not assumed from memory.
 *
 * `amount` is DECIMAL major units (e.g. "100.00" UAH) — unlike Monobank/Stripe, LiqPay does not
 * use minor units. Our own `payments.amount` column is always minor units (package-wide design,
 * see "Money" in the plan) — this driver is the one doing the /100 conversion, not the caller.
 */
class LiqPayGateway extends AbstractGateway implements RefundsPayments, ChecksPaymentStatus
{
    protected const CHECKOUT_URL = 'https://www.liqpay.ua/api/3/checkout';

    protected const API_URL = 'https://www.liqpay.ua/api/request';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $params = array_filter([
            'version' => 3,
            'public_key' => $this->publicKey(),
            'action' => 'pay',
            'amount' => $this->formatAmount($payment->amount),
            'currency' => $payment->currency_code,
            'description' => $options->description ?? "Payment #{$payment->id}",
            'order_id' => (string) $payment->id,
            'result_url' => $this->successUrl($options),
            'server_url' => $this->webhookUrl($options),
            'language' => $options->locale,
        ], static fn ($value) => $value !== null && $value !== '');

        $data = base64_encode(json_encode($params, JSON_THROW_ON_ERROR));

        return new PaymentResult(
            form: [
                'action' => self::CHECKOUT_URL,
                'fields' => ['data' => $data, 'signature' => $this->sign($data)],
            ],
        );
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        // spatie already verified the signature (LiqPaySignatureValidator) before this ran — the
        // stored payload is the raw POST fields (['data' => ..., 'signature' => ...]), still encoded.
        $decoded = json_decode(base64_decode($webhookCall->payload['data']), true, 512, JSON_THROW_ON_ERROR);

        $payment = Payment::findOrFail($decoded['order_id']);

        $status = match ($decoded['status']) {
            'success', 'sandbox' => PaymentStatus::Paid,
            'failure', 'error' => PaymentStatus::Failed,
            // 'reversed' (chargeback/refund via LiqPay's own dashboard, not our refund() call) —
            // a v3+ nuance same as the rest of the "recognized but no consumer yet" list in the
            // plan; explicit RefundsPayments::refund() below is the primary, supported path.
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $decoded);
        }

        $payment->update(['status' => $status, 'external_id' => $decoded['payment_id'] ?? $decoded['transaction_id'] ?? null]);

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
        ]);

        return new PaymentResult(externalId: $payment->external_id, raw: $response);
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

        $externalId = (string) ($data['payment_id'] ?? $data['order_id']);
        $payment->update(['status' => $status, 'external_id' => $externalId]);

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

    public static function label(): string
    {
        return 'LiqPay';
    }

    public static function credentialFields(): array
    {
        return [
            ['name' => 'public_key', 'type' => 'text', 'secret' => false, 'help' => 'Публічний ключ з кабінету LiqPay'],
            ['name' => 'private_key', 'type' => 'text', 'secret' => true, 'help' => 'Приватний ключ з кабінету LiqPay'],
        ];
    }

    public static function supportedCurrencies(): array
    {
        return ['UAH', 'USD', 'EUR'];
    }

    protected function api(array $params): array
    {
        $params = array_filter(['version' => 3, 'public_key' => $this->publicKey(), ...$params], static fn ($v) => $v !== null);

        $data = base64_encode(json_encode($params, JSON_THROW_ON_ERROR));

        return Http::asForm()
            ->timeout(15)
            ->retry(2, 200)
            ->post(self::API_URL, ['data' => $data, 'signature' => $this->sign($data)])
            ->throw()
            ->json();
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

    protected function publicKey(): string
    {
        return $this->credentials['public_key'] ?? throw new BillingException('LiqPay: credential "public_key" is missing.');
    }

    protected function privateKey(): string
    {
        return $this->credentials['private_key'] ?? throw new BillingException('LiqPay: credential "private_key" is missing.');
    }
}
