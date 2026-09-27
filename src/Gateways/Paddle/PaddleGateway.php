<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Paddle;

use Fomvasss\Billing\Contracts\ChecksGatewayHealth;
use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\GatewayHealth;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Gateways\AbstractGateway;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Paddle Billing (not Classic) — api.paddle.com / sandbox-api.paddle.com, verified against
 * developer.paddle.com (api-reference/transactions/create-transaction, build/transactions/
 * default-payment-link, webhooks/about/signature-verification).
 *
 * Paddle is a Merchant of Record, and that shapes what this driver can be:
 *  - No off-session charge outside a Paddle subscription — saved payment methods can only be
 *    listed/deleted, never charged. Hence no TokenizesPaymentMethod.
 *  - No Paddle-hosted checkout page for the web: a transaction's checkout.url is a page on an
 *    APPROVED domain that loads Paddle.js, which opens the checkout by the `_ptxn` query parameter.
 *    That page is the package's own billing.paddle.checkout route (PaddleCheckoutController),
 *    set as the account's default payment link.
 *  - Non-catalog items: the price and product travel inline in the transaction, so nothing has to
 *    be mirrored into Paddle's catalog.
 *
 * Amounts are strings in minor units on both sides — no conversion (unlike LiqPay/WayForPay).
 * custom_data.payment_id is our link back to the row; Paddle echoes it in every transaction event.
 */
class PaddleGateway extends AbstractGateway implements ChecksPaymentStatus, ChecksGatewayHealth
{
    protected const LIVE_URL = 'https://api.paddle.com';

    protected const SANDBOX_URL = 'https://sandbox-api.paddle.com';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        // A Paddle transaction never expires on its own — a re-issue (billing.pay) would otherwise
        // leave the previous checkout payable next to the new one, and a customer holding both
        // links could pay twice.
        $this->cancelPreviousTransaction($payment);

        $data = $this->http()->post('/transactions', [
            // Gateway-specific extras (discount_id, customer_id, ...). Merged first so the driver's
            // own fields below always win.
            ...$options->raw,
            'items' => $this->items($payment, $options),
            'currency_code' => $payment->currency,
            'custom_data' => ['payment_id' => (string) $payment->id],
            // No checkout.url: Paddle then uses the account's default payment link, which is the
            // billing.paddle.checkout page. Passing our own URL instead is refused unless its domain
            // went through Website approval — even in the sandbox (live-verified) — and the page
            // finds the payment by the ?_ptxn= Paddle appends anyway.
        ])->throw()->json('data');

        $expiresAt = now()->addMinutes($this->linkTtlMinutes());

        // The checkout page is a plain GET Paddle.js drives — it can't receive ChargeOptions, so
        // the per-charge settings wait for it in the cache (same approach as LiqPay's form).
        Cache::put("billing.paddle_checkout.{$payment->id}", array_filter([
            'success_url' => $this->successUrl($payment, $options),
            'fail_url' => $this->failUrl($payment, $options),
            'locale' => $options->locale,
        ]), $expiresAt);

        return new PaymentResult(
            url: $data['checkout']['url'] ?? throw new BillingException('Paddle: the transaction came back without a checkout URL.'),
            expiresAt: $expiresAt,
            externalId: $data['id'],
            raw: $data,
        );
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $event = $webhookCall->payload;
        $transaction = $event['data'] ?? [];

        $status = match ($event['event_type'] ?? null) {
            // completed, not paid: `paid` arrives before Paddle has finished processing — no fee,
            // no payout totals yet. completed carries everything in one event.
            'transaction.completed' => PaymentStatus::Paid,
            'transaction.canceled' => PaymentStatus::Canceled,
            // transaction.payment_failed is deliberately not here: on a checkout the customer is
            // still on the page and can try another card, so a declined attempt is not an outcome.
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        // A transaction this package didn't create (another integration on the same account) is
        // Ignored, not a failed job.
        $payment = $this->findPaymentByReference($transaction['custom_data']['payment_id'] ?? null);

        if ($payment === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        return $this->applyTransaction($payment, $status, $transaction, $event);
    }

    public function checkStatus(Payment $payment): WebhookResult
    {
        if ($payment->external_id === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored');
        }

        $transaction = $this->http()->get("/transactions/{$payment->external_id}")->throw()->json('data');

        $status = match ($transaction['status'] ?? null) {
            'completed' => PaymentStatus::Paid,
            'canceled' => PaymentStatus::Canceled,
            // An open checkout nobody paid. Paddle never expires it, so the link's own TTL is the
            // only end it gets — cancel it on Paddle's side first, so it can't be paid afterwards.
            'draft', 'ready' => $payment->payment_url_expires_at?->isPast() ? $this->cancelTransaction($payment->external_id) : null,
            // paid → completed is seconds away; billed/past_due belong to invoices and renewals.
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $transaction);
        }

        return $this->applyTransaction($payment, $status, $transaction, $transaction);
    }

    /** GET /event-types — needs no permissions and no entities, so it only proves the key works. */
    public function healthCheck(): GatewayHealth
    {
        return $this->probeHealth(function () {
            $this->http()->retry(1)->get('/event-types')->throw();

            return $this->isSandbox() ? 'sandbox' : 'live';
        });
    }

    public static function label(): string
    {
        return 'Paddle';
    }

    /**
     * Events go only to notification destinations registered on Paddle's side — there is no
     * per-transaction callback URL. `billing:paddle-register-webhook` registers one via the API.
     */
    public static function requiresDashboardWebhook(): bool
    {
        return true;
    }

    public static function credentialFields(): array
    {
        return [
            ['name' => 'api_key', 'type' => 'text', 'secret' => true, 'help' => 'API key (pdl_live_apikey_... / pdl_sdbx_apikey_...) — Paddle > Developer tools > Authentication'],
            ['name' => 'client_token', 'type' => 'text', 'secret' => false, 'help' => 'Client-side token (live_... / test_...) для Paddle.js на сторінці оплати'],
            ['name' => 'webhook_secret', 'type' => 'text', 'secret' => true, 'help' => 'Secret key notification destination (pdl_ntfset_...)'],
            ['name' => 'tax_category', 'type' => 'text', 'secret' => false, 'help' => 'Податкова категорія inline-продукту: standard, saas, digital-goods, ... (має бути увімкнена в акаунті)'],
            ['name' => 'link_ttl_minutes', 'type' => 'number', 'secret' => false, 'help' => 'Скільки хвилин живе посилання на оплату (за замовчуванням 1440)'],
        ];
    }

    public static function supportedCurrencies(): array
    {
        // The currency_code list from api-reference/transactions/create-transaction (fetched
        // 2026-09-27), MINUS the zero-decimal ones (JPY, CLP, KRW, VND) — Money and the drivers
        // assume 2-decimal minor units throughout. Minimum amounts per currency apply (UAH 29.00).
        return [
            'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'CHF', 'HKD', 'SGD', 'SEK', 'ARS', 'BRL', 'CNY',
            'COP', 'CZK', 'DKK', 'HUF', 'ILS', 'INR', 'MXN', 'NOK', 'NZD', 'PEN', 'PLN', 'RUB',
            'THB', 'TRY', 'TWD', 'UAH', 'ZAR',
        ];
    }

    /** The sandbox is a separate API host, and a key works on its own host only — the key says which. */
    public function isSandbox(): bool
    {
        return str_starts_with($this->apiKey(), 'pdl_sdbx_');
    }

    protected function applyTransaction(Payment $payment, PaymentStatus $status, array $transaction, array $raw): WebhookResult
    {
        // A canceled transaction that is no longer this payment's checkout — the one charge()
        // canceled itself when it re-issued the link. The row already points at a live successor.
        if ($status === PaymentStatus::Canceled && ($transaction['id'] ?? null) !== $payment->external_id) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $raw);
        }

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            $this->issuedAmount($transaction),
            $transaction['currency_code'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $raw);
        }

        if (! $payment->transitionTo($status, array_filter([
            'external_id' => $transaction['id'] ?? null,
            // Paddle's fee in the transaction's currency; null until the transaction is completed.
            ...($status === PaymentStatus::Paid ? $this->feeFrom($transaction['details']['totals']['fee'] ?? null) : []),
        ], fn ($value) => $value !== null))) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $raw);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: $status === PaymentStatus::Paid ? 'succeeded' : 'canceled',
            payment: $payment,
            externalId: $transaction['id'] ?? (string) $payment->id,
            raw: $raw,
        );
    }

    /**
     * What the transaction was issued for — the unit prices we sent, times quantity. Not a totals
     * field: `subtotal` excludes tax, so under an inclusive tax_mode it comes out below the amount
     * we charged, and `total` includes tax, so under an exclusive one it comes out above it. The
     * stale-link case the check exists for (paid after the amount was edited) shows up here all
     * the same, since the old transaction carries the old unit price.
     */
    protected function issuedAmount(array $transaction): ?int
    {
        $items = $transaction['items'] ?? null;

        if (! is_array($items) || $items === []) {
            return null;
        }

        return array_sum(array_map(
            fn (array $item) => (int) ($item['price']['unit_price']['amount'] ?? 0) * (int) ($item['quantity'] ?? 1),
            $items,
        ));
    }

    protected function items(Payment $payment, ChargeOptions $options): array
    {
        $lines = $options->receiptItems !== []
            ? array_map(fn (array $item) => [$item['name'], (int) $item['unitAmount'], (int) $item['qty']], $options->receiptItems)
            : [[$options->description ?? "Payment #{$payment->id}", $payment->amount, 1]];

        return array_map(fn (array $line) => [
            'quantity' => $line[2],
            'price' => [
                // Internal note, never shown to the customer — Paddle requires 2+ characters.
                'description' => "Payment {$payment->id}",
                // Paddle's checkout shows a quantity stepper by default (1–100) — a customer bumping
                // it would pay a sum this payment isn't for, which the amount check then refuses:
                // money taken, row left pending. Pinning the range removes the stepper.
                'quantity' => ['minimum' => $line[2], 'maximum' => $line[2]],
                'unit_price' => [
                    'amount' => (string) $line[1],
                    'currency_code' => $payment->currency,
                ],
                'product' => [
                    'name' => Str::limit($line[0], 197),
                    'tax_category' => $this->credentials['tax_category'] ?? 'standard',
                ],
            ],
        ], $lines);
    }

    /** Best effort: a transaction that can't be canceled any more (already paid) must not block the re-issue. */
    protected function cancelPreviousTransaction(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Paid || ! str_starts_with((string) $payment->external_id, 'txn_')) {
            return;
        }

        try {
            $this->cancelTransaction($payment->external_id);
        } catch (\Throwable $exception) {
            $this->log('cancelPreviousTransaction', ['payment_id' => $payment->id, 'error' => $exception->getMessage()]);
        }
    }

    protected function cancelTransaction(string $transactionId): PaymentStatus
    {
        $this->http()->patch("/transactions/{$transactionId}", ['status' => 'canceled'])->throw();

        return PaymentStatus::Canceled;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->isSandbox() ? self::SANDBOX_URL : self::LIVE_URL)
            ->withToken($this->apiKey())
            ->withHeaders(['Paddle-Version' => '1'])
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 200);
    }

    protected function apiKey(): string
    {
        return $this->credentials['api_key'] ?? throw new BillingException('Paddle: credential "api_key" is missing.');
    }
}
