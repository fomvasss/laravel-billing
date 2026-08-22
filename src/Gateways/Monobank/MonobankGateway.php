<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Monobank;

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
use Illuminate\Support\Facades\Http;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * https://api.monobank.ua — verified against the official Acquiring API docs (invoice.md/
 * payment.md/webhook.md/merchant.md/wallet.md), not the dropshop reference (which mostly matches,
 * but `amount` there is major units × 100 — ours is already minor units, no conversion needed).
 *
 * TokenizesPaymentMethod, with one asterisk: Monobank never hands you a card token synchronously
 * — it only exists as a side effect of a completed checkout with saveCardData.saveCard=true, and
 * arrives later via handleWebhook()'s `walletData` (see there), which auto-attaches it — no manual
 * step needed for the common case. attachPaymentMethod() here is for the secondary case: a token
 * already known some other way (e.g. `GET /wallet`), registered without a fresh charge. Confirmed
 * against `support`'s production `MonobankPaymentService` (`wallet/payment` + `initiationKind:
 * merchant`, the same MIT — merchant-initiated-transaction — pattern used here).
 */
class MonobankGateway extends AbstractGateway implements RefundsPayments, ChecksPaymentStatus, TokenizesPaymentMethod, \Fomvasss\Billing\Contracts\ChecksGatewayHealth
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
            // Gateway-specific extras (Monobank's x_cms, agentFeePercent, ...). Merged first so
            // the driver's own fields below always win — raw adds, never overrides amount/reference.
            ...$options->raw,
            'amount' => $payment->amount,
            'ccy' => $this->currencyCode($payment->currency),
            'merchantPaymInfo' => array_filter([
                'reference' => (string) $payment->id,
                'destination' => $options->description,
                'customerEmails' => $options->customerEmail ? [$options->customerEmail] : null,
                'basketOrder' => $this->basketOrder($options->receiptItems),
            ]),
            'redirectUrl' => $this->successUrl($payment, $options),
            'webHookUrl' => $this->webhookUrl($options),
            'validity' => $this->linkTtlMinutes(60) * 60,
            'saveCardData' => $options->saveCard
                ? ['saveCard' => true, 'walletId' => $this->walletId($payment->billable_type, (string) $payment->billable_id)]
                : null,
        ]))->throw();

        $data = $response->json();

        return new PaymentResult(
            url: $data['pageUrl'],
            expiresAt: now()->addMinutes($this->linkTtlMinutes(60)),
            externalId: $data['invoiceId'],
        );
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $payload = $webhookCall->payload;

        // Same schema as GET /invoice/status — "reference" is exactly what we set to $payment->id on charge().
        // A webhook for a payment this package didn't create (another integration on the same
        // merchant account, a row created before install) is Ignored, not a failed job.
        $payment = $this->findPaymentByReference($payload['reference'] ?? null);

        if ($payment === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        $status = match ($payload['status'] ?? null) {
            'success' => PaymentStatus::Paid,
            'failure' => PaymentStatus::Failed,
            // created/processing/hold — intermediate, not terminal (see "Набір статусів" in the package plan)
            default => null,
        };

        // Checked before anything is acted on, tokenization included: a delivery whose sum doesn't
        // match this row isn't trustworthy evidence about it at all.
        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($payload['amount']) ? (int) $payload['amount'] : null,
            isset($payload['ccy']) ? (string) array_search((int) $payload['ccy'], self::CURRENCY_CODES, true) : null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        // Card tokenization completes asynchronously relative to the payment itself — Monobank
        // re-delivers this same invoice-status webhook once walletData.status flips "new" →
        // "created", even when the top-level payment `status` hasn't changed since the previous
        // delivery (confirmed against `support`'s production integration, which observes the same
        // two-delivery pattern). Handled as a side effect rather than instead of the payment
        // status, the way LiqPay/WayForPay/Hutko's inline tokens are: nothing guarantees the two
        // arrive in separate deliveries, and a single delivery carrying both used to leave the
        // payment pending until reconciliation an hour later. Only reachable when charge() ran with
        // ChargeOptions::$saveCard.
        if (($payload['walletData']['status'] ?? null) === 'created' && ! empty($payload['walletData']['cardToken'])) {
            $this->attachFromWebhook($payment, $payload);
        }

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        // paymentInfo.fee — the bank's commission, minor units in `ccy` (live-verified: 72 on a
        // 5500 charge ≈ 1.31%). Only some deliveries carry paymentInfo.
        if (! $payment->transitionTo($status, [
            'external_id' => $payload['invoiceId'],
            ...$this->feeFrom($payload['paymentInfo']['fee'] ?? null),
        ])) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

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
        // retry(1): same reasoning as chargePaymentMethod() — a retried cancel after a timeout
        // can return money twice.
        $response = $this->http()->retry(1)->post('/api/merchant/invoice/cancel', array_filter([
            'invoiceId' => $payment->external_id,
            'amount' => $amount?->amount,
        ]))->throw();

        $data = $response->json();

        // HTTP 200 with status=failure is Monobank's way of refusing a cancellation (nothing left
        // to return, the invoice is too old, ...). Without this check the caller would record a
        // refund row for money that never moved. `processing` is accepted: the reversal is queued
        // and will settle.
        if (($data['status'] ?? null) === 'failure') {
            throw new BillingException('Monobank: refund was refused: ' . json_encode($data));
        }

        return new PaymentResult(externalId: $payment->external_id, raw: $data);
    }

    /** No API call — Monobank has no "create wallet" endpoint, walletId is ours to pick and stable per billable. */
    public function createCustomer(Model&Billable $billable): string
    {
        return $this->walletId($billable::class, (string) $billable->getKey());
    }

    /**
     * The uncommon path — see class docblock. $token = ['card_token' => '...'], already obtained
     * some other way than this driver's own webhook auto-attach (handleWalletData()). Verifies the
     * token is actually present in the wallet before persisting it.
     */
    public function attachPaymentMethod(Model&Billable $billable, array $token): PaymentMethod
    {
        $cardToken = $token['card_token'] ?? throw new BillingException('Monobank: token must include "card_token".');
        $walletId = $this->walletId($billable::class, (string) $billable->getKey());

        $wallet = $this->http()->get('/api/merchant/wallet', ['walletId' => $walletId])->throw()->json('wallet', []);
        $card = collect($wallet)->firstWhere('cardToken', $cardToken)
            ?? throw new BillingException("Monobank: card token not found in wallet \"{$walletId}\".");

        $maskedPan = $card['maskedPan'] ?? null;

        $method = $this->persistPaymentMethod(
            $billable::class,
            (string) $billable->getKey(),
            $billable->tenantId(),
            $walletId,
            $cardToken,
            $maskedPan !== null ? substr($maskedPan, -4) : null,
        );

        PaymentMethodAttached::dispatch($method);

        return $method;
    }

    /**
     * initiationKind=merchant — MIT (merchant-initiated transaction), the off-session/no-3DS
     * counterpart to the CIT (customer-initiated) checkout charge() does. The outcome still
     * resolves through handleWebhook() same as any other Payment — this only initiates it.
     */
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        // retry(1) overrides http()'s default: Laravel retries a ConnectionException too, and a
        // timeout says nothing about whether the bank already debited the card. Monobank has no
        // idempotency key and does not deduplicate by `reference`, so a second attempt is a second
        // debit — a failed initiation is written off by the caller instead (see
        // ProcessRecurringChargesCommand).
        $response = $this->http()->retry(1)->post('/api/merchant/wallet/payment', array_filter([
            ...$options->raw,
            'cardToken' => $method->external_id,
            'amount' => $payment->amount,
            'ccy' => $this->currencyCode($payment->currency),
            'initiationKind' => 'merchant',
            'merchantPaymInfo' => array_filter([
                'reference' => (string) $payment->id,
                'basketOrder' => $this->basketOrder($options->receiptItems),
            ]),
            'webHookUrl' => route('billing.webhook', ['gateway' => $this->gatewayName]),
        ]))->throw();

        $data = $response->json();

        return new PaymentResult(externalId: $data['invoiceId'] ?? null, raw: $data);
    }

    public function detachPaymentMethod(PaymentMethod $method): void
    {
        $this->http()->send('DELETE', '/api/merchant/wallet/card', ['query' => ['cardToken' => $method->external_id]])->throw();

        $method->delete();

        PaymentMethodDetached::dispatch($method);
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

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($data['amount']) ? (int) $data['amount'] : null,
            isset($data['ccy']) ? (string) array_search((int) $data['ccy'], self::CURRENCY_CODES, true) : null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        if (! $payment->transitionTo($status, [
            'external_id' => $data['invoiceId'],
            ...$this->feeFrom($data['paymentInfo']['fee'] ?? null),
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
            externalId: $data['invoiceId'],
            raw: $data,
        );
    }

    /** GET /api/merchant/details — validates the X-Token, returns the merchant identity (live-verified: 403 FORBIDDEN on a bad token). */
    public function healthCheck(): \Fomvasss\Billing\DTO\GatewayHealth
    {
        return $this->probeHealth(function () {
            $data = $this->http()->retry(1)->get('/api/merchant/details')->throw()->json();

            return $data['merchantName'] ?? $data['merchantId'] ?? null;
        });
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
    protected function walletId(string $billableType, string $billableId): string
    {
        return md5($billableType . ':' . $billableId);
    }

    /**
     * The auto-attach path handleWebhook() defers to — turns a freshly tokenized card
     * (walletData.status="created") into a persisted PaymentMethod, no attachPaymentMethod() call
     * needed for the common "charge once with saveCard, tokenize as a side effect" flow.
     */
    /** Persisted as a side effect and dispatched directly — same shape as LiqPay/WayForPay/Hutko's. */
    protected function attachFromWebhook(Payment $payment, array $payload): void
    {
        $billable = $payment->billable;
        $maskedPan = $payload['paymentInfo']['maskedPan'] ?? null;

        $method = $this->persistPaymentMethod(
            $payment->billable_type,
            (string) $payment->billable_id,
            $billable instanceof Billable ? $billable->tenantId() : null,
            $payload['walletData']['walletId'],
            $payload['walletData']['cardToken'],
            $maskedPan !== null ? substr($maskedPan, -4) : null,
            $payload['paymentInfo']['paymentSystem'] ?? null,
        );

        // Direct dispatch runs BEFORE ProcessWebhookJob's dedup claim, so a re-delivered callback
        // would fire it again — wasRecentlyCreated is the dedup here: only a token not yet
        // persisted counts as "attached".
        if ($method->wasRecentlyCreated) {
            PaymentMethodAttached::dispatch($method);
        }
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
