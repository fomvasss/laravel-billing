<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Monobank;

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
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * https://api.monobank.ua — verified against the official Acquiring API docs (invoice.md/
 * payment.md/webhook.md/merchant.md), not the dropshop reference (which mostly matches, but
 * `amount` there is major units × 100 — ours is already minor units, no conversion needed).
 *
 * No TokenizesPaymentMethod: Monobank has no "attach a card synchronously" endpoint — a card only
 * gets tokenized as a side effect of a completed checkout with saveCardData.saveCard=true, and the
 * resulting cardToken/walletId arrive later in the webhook's `walletData`, not from a frontend SDK
 * call the way Stripe/LiqPay work. That shape doesn't fit attachPaymentMethod(Billable, array
 * $token), so this driver doesn't claim the capability — see "Кроки реалізації" п.5 for the
 * reasoning. wallet/payment (token charge) is still usable directly once a token exists, just not
 * through the standard contract.
 */
class MonobankGateway extends AbstractGateway implements RefundsPayments, ChecksPaymentStatus
{
    protected const BASE_URL = 'https://api.monobank.ua';

    protected const CURRENCY_CODES = [
        'UAH' => 980,
        'USD' => 840,
        'EUR' => 978,
    ];

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $response = $this->http()->post('/api/merchant/invoice/create', array_filter([
            'amount' => $payment->amount,
            'ccy' => $this->currencyCode($payment->currency_code),
            'merchantPaymInfo' => array_filter([
                'reference' => (string) $payment->id,
                'destination' => $options->description,
                'customerEmails' => $options->customerEmail ? [$options->customerEmail] : null,
                'basketOrder' => $this->basketOrder($options->receiptItems),
            ]),
            'redirectUrl' => $this->successUrl($options),
            'webHookUrl' => $this->webhookUrl($options),
            'validity' => (int) ($this->credentials['link_ttl_minutes'] ?? 60) * 60,
            'saveCardData' => $options->saveCard ? ['saveCard' => true, 'walletId' => $this->walletId($payment)] : null,
        ]))->throw();

        $data = $response->json();

        return new PaymentResult(
            url: $data['pageUrl'],
            expiresAt: now()->addSeconds((int) ($this->credentials['link_ttl_minutes'] ?? 60) * 60),
            externalId: $data['invoiceId'],
        );
    }

    public function handleWebhook(WebhookCall $webhookCall): WebhookResult
    {
        $payload = $webhookCall->payload;

        // Same schema as GET /invoice/status — "reference" is exactly what we set to $payment->id on charge().
        $payment = Payment::findOrFail($payload['reference']);

        $status = match ($payload['status'] ?? null) {
            'success' => PaymentStatus::Paid,
            'failure' => PaymentStatus::Failed,
            // created/processing/hold — intermediate, not terminal (see "Набір статусів" in the package plan)
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        $payment->update(['status' => $status, 'external_id' => $payload['invoiceId']]);

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: $status === PaymentStatus::Paid ? 'succeeded' : 'failed',
            payment: $payment,
            externalId: $payload['invoiceId'],
            raw: $payload,
        );
    }

    public function refund(Payment $payment, ?Money $amount = null): PaymentResult
    {
        $response = $this->http()->post('/api/merchant/invoice/cancel', array_filter([
            'invoiceId' => $payment->external_id,
            'amount' => $amount?->amount,
        ]))->throw();

        return new PaymentResult(externalId: $payment->external_id, raw: $response->json());
    }

    public function checkStatus(Payment $payment): WebhookResult
    {
        $data = $this->http()
            ->get('/api/merchant/invoice/status', ['invoiceId' => $payment->external_id])
            ->throw()
            ->json();

        $status = match ($data['status']) {
            'success' => PaymentStatus::Paid,
            'failure' => PaymentStatus::Failed,
            'expired' => PaymentStatus::Canceled,
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        $payment->update(['status' => $status, 'external_id' => $data['invoiceId']]);

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: match ($status) {
                PaymentStatus::Paid => 'succeeded',
                PaymentStatus::Failed => 'failed',
                default => 'canceled',
            },
            payment: $payment,
            externalId: $data['invoiceId'],
            raw: $data,
        );
    }

    public static function label(): string
    {
        return 'Monobank Acquiring';
    }

    public static function credentialFields(): array
    {
        return [
            ['name' => 'token', 'type' => 'text', 'secret' => true, 'help' => 'X-Token з кабінету мерчанта (web.monobank.ua) або тестовий з api.monobank.ua'],
            ['name' => 'link_ttl_minutes', 'type' => 'number', 'secret' => false, 'help' => 'TTL посилання на оплату, хв (validity), дефолт 60'],
        ];
    }

    public static function supportedCurrencies(): array
    {
        return array_keys(self::CURRENCY_CODES);
    }

    protected function http(): PendingRequest
    {
        $token = $this->credentials['token'] ?? throw new BillingException('Monobank: credential "token" is missing.');

        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-Token' => $token])
            ->timeout(15)
            ->retry(2, 200);
    }

    protected function currencyCode(string $currency): int
    {
        return self::CURRENCY_CODES[$currency] ?? throw BillingException::unsupportedCurrency($currency, $this->gatewayName);
    }

    /** Stable per-billable id — Monobank has no separate "create wallet" call, walletId is just ours to pick. */
    protected function walletId(Payment $payment): string
    {
        return md5($payment->billable_type . ':' . $payment->billable_id);
    }

    protected function basketOrder(array $receiptItems): ?array
    {
        if ($receiptItems === []) {
            return null;
        }

        return array_map(static fn (array $item) => array_filter([
            'name' => $item['name'],
            'qty' => $item['qty'],
            'sum' => $item['unitAmount'],
            'code' => $item['sku'] ?? null,
        ]), $receiptItems);
    }
}
