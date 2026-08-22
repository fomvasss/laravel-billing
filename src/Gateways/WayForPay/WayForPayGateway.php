<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\WayForPay;

use Fomvasss\Billing\Contracts\Billable;
use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
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
 * https://secure.wayforpay.com/pay?behavior=offline (host2host Purchase, returns a redirectable
 * `url` — no client-submitted form) + https://api.wayforpay.com/api (server-server, CHECK_STATUS/
 * CHARGE). Verified against wiki.wayforpay.com "Accept payment (Purchase)"/"Checking of payment
 * status"/"To accept payment host2host (Charge)" and the official PHP SDK (github.com/wayforpay/
 * php-sdk, `src/Request/ChargeRequest.php` — signature and acknowledgment-response formats were
 * NOT in dropshop's reference at the level of detail needed (see WayForPayWebhookResponder)).
 *
 * `?behavior=offline` (documented for mobile apps, but works the same for any server-to-server
 * caller) is what `dropshop`'s WayForPay integration actually uses in production instead of the
 * client-submitted checkout form the plain (non-offline) `/pay` endpoint requires — same signed
 * request either way, this driver just adds the query flag and reads `url` back synchronously
 * instead of handing the browser a form to POST.
 *
 * `amount` — decimal major units (e.g. "100.00" UAH), same as LiqPay, not minor units.
 *
 * No RefundsPayments in v1 — WayForPay's refund endpoint (transactionType: REFUND) needs the same
 * host2host access tier as CHECK_STATUS/Charge, not confirmed available on a standard merchant
 * account; adding the interface without being able to verify the exact request shape against docs
 * would be guessing, not porting. Add when a real credential is available to test against.
 *
 * TokenizesPaymentMethod: unlike LiqPay/Monobank, there's no opt-in flag — `recToken` comes back
 * automatically in the response/callback of ANY approved card Purchase (confirmed in both the
 * wiki's response-parameters table and the SDK's Transaction::getRecToken()), so handleWebhook()
 * persists it unconditionally whenever present on a successful payment. No gateway-side
 * token-revocation endpoint is documented — detachPaymentMethod() is local-only, same reasoning as
 * LiqPay's.
 */
class WayForPayGateway extends AbstractGateway implements ChecksPaymentStatus, TokenizesPaymentMethod, \Fomvasss\Billing\Contracts\ChecksGatewayHealth
{
    protected const CHECKOUT_URL = 'https://secure.wayforpay.com/pay';

    protected const API_URL = 'https://api.wayforpay.com/api';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $products = $this->products($options->receiptItems, $payment, $options);

        $orderDate = now()->timestamp;

        $fields = array_filter([
            // Gateway-specific extras (orderLifetime, paymentSystems, delivery fields, ...). Merged
            // first so the driver's own fields below always win — and, critically, so raw can never
            // desync a field from the signature computed over them further down.
            ...$options->raw,
            'merchantAccount' => $this->merchantAccount(),
            'merchantAuthType' => 'SimpleSignature',
            'merchantDomainName' => $this->merchantDomainName(),
            'merchantTransactionSecureType' => 'AUTO',
            'orderReference' => (string) $payment->id,
            'orderDate' => $orderDate,
            'amount' => $this->formatAmount($payment->amount),
            'currency' => $payment->currency,
            'productName' => $products['name'],
            'productCount' => $products['count'],
            'productPrice' => $products['price'],
            'clientEmail' => $options->customerEmail,
            'returnUrl' => $this->successUrl($payment, $options),
            'serviceUrl' => $this->webhookUrl($options),
            // Explicit checkout TTL (seconds; docs allow 60..1728000) — so payment_url_expires_at
            // below is a real number instead of "unknown, hope for the best".
            'orderLifetime' => $this->linkTtlMinutes() * 60,
        ], static fn ($value) => $value !== null && $value !== '');

        $fields['merchantSignature'] = $this->sign([
            $fields['merchantAccount'], $fields['merchantDomainName'], $fields['orderReference'],
            $fields['orderDate'], $fields['amount'], $fields['currency'],
            ...$fields['productName'], ...$fields['productCount'], ...$fields['productPrice'],
        ]);

        $data = Http::timeout(15)->retry(2, 200)
            ->post(self::CHECKOUT_URL . '?behavior=offline', $fields)
            ->throw()
            ->json();

        if (! isset($data['url'])) {
            throw new BillingException('WayForPay: purchase request did not return a url: ' . json_encode($data));
        }

        return new PaymentResult(url: $data['url'], expiresAt: now()->addMinutes($this->linkTtlMinutes()));
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $payload = $webhookCall->payload;

        // A callback for a payment this package didn't create is Ignored, not a failed job.
        $payment = $this->findPaymentByReference($payload['orderReference'] ?? null);

        if ($payment === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        // Refunded/Voided — a reversal carried out on WayForPay's side. Recognized and reported,
        // but not turned into a refund row: unlike every other gateway here, WayForPay's serviceUrl
        // callback documents no refunded-amount field at all (wiki.wayforpay.com/en/view/852102
        // lists `amount` as "Amount of order", and says nothing about what it holds for a reversal),
        // so there is no figure to record that wouldn't be a guess. See reportUnrecordedReversal().
        if (in_array($payload['transactionStatus'] ?? null, ['Refunded', 'Voided'], true)) {
            $this->reportUnrecordedReversal($payment, $payload);

            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        $status = match ($payload['transactionStatus'] ?? null) {
            'Approved' => PaymentStatus::Paid,
            'Declined' => PaymentStatus::Failed,
            // Canceled, not Failed — same as every other driver's `expired`: nobody's card was
            // refused, the customer just never finished the checkout, and calling that a failure
            // puts a subscription into dunning over an abandoned link.
            'Expired' => PaymentStatus::Canceled,
            // Pending/InProcessing/RefundInProcessing — not terminal
            default => null,
        };

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($payload['amount']) ? (int) round((float) $payload['amount'] * 100) : null,
            $payload['currency'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        // recToken rides along in the SAME callback as the payment status, automatically on any
        // approved card payment (no opt-in flag — see class docblock). Persisted as a side effect,
        // dispatched directly: this call's WebhookResult already carries the Payment outcome below,
        // so there's no second return value for WebhookResultDispatcher to fire
        // PaymentMethodAttached from.
        if ($status === PaymentStatus::Paid && ! empty($payload['recToken'])) {
            $this->attachFromWebhook($payment, $payload);
        }

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        // fee — WayForPay's commission, decimal like the callback's own `amount`.
        if (! $payment->transitionTo($status, $this->feeFrom($payload['fee'] ?? null, decimal: true))) {
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
            externalId: (string) $payload['orderReference'],
            raw: $payload,
        );
    }

    /** No gateway-side "customer" object — same reasoning as Monobank/LiqPay, ours to pick and stable per billable. */
    public function createCustomer(Model&Billable $billable): string
    {
        return $this->customerId($billable::class, (string) $billable->getKey());
    }

    /**
     * The uncommon path — see class docblock. $token = ['rec_token' => '...'], already obtained
     * some other way than this driver's own webhook auto-attach (attachFromWebhook()). No API call
     * to verify it against — not documented for WayForPay, same limitation as LiqPay's.
     */
    public function attachPaymentMethod(Model&Billable $billable, array $token): PaymentMethod
    {
        $recToken = $token['rec_token'] ?? throw new BillingException('WayForPay: token must include "rec_token".');

        $method = $this->persistPaymentMethod(
            $billable::class,
            (string) $billable->getKey(),
            $billable->tenantId(),
            $this->customerId($billable::class, (string) $billable->getKey()),
            $recToken,
        );

        PaymentMethodAttached::dispatch($method);

        return $method;
    }

    /**
     * transactionType=CHARGE (host2host) — same signature fields/order as charge()'s Purchase
     * (merchantAccount;merchantDomainName;orderReference;orderDate;amount;currency;productName[];
     * productCount[];productPrice[]), confirmed in ChargeRequest::getRequestSignatureFieldsValues().
     * merchantTransactionSecureType=NON3DS — off-session, no customer present to complete a 3DS
     * challenge. The outcome still resolves through handleWebhook() same as any other Payment; this
     * only initiates it.
     */
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $orderDate = now()->timestamp;
        $amount = $this->formatAmount($payment->amount);
        $products = $this->products($options->receiptItems, $payment, $options);

        $fields = array_filter([
            ...$options->raw,
            'transactionType' => 'CHARGE',
            'merchantAccount' => $this->merchantAccount(),
            'merchantAuthType' => 'SimpleSignature',
            'merchantDomainName' => $this->merchantDomainName(),
            'merchantTransactionType' => 'SALE',
            'merchantTransactionSecureType' => 'NON3DS',
            'orderReference' => (string) $payment->id,
            'orderDate' => $orderDate,
            'amount' => $amount,
            'currency' => $payment->currency,
            'productName' => $products['name'],
            'productCount' => $products['count'],
            'productPrice' => $products['price'],
            'recToken' => $method->external_id,
            'serviceUrl' => $this->webhookUrl($options),
            'apiVersion' => 1,
        ], static fn ($value) => $value !== null && $value !== '');

        $fields['merchantSignature'] = $this->sign([
            $fields['merchantAccount'], $fields['merchantDomainName'], $fields['orderReference'],
            $fields['orderDate'], $fields['amount'], $fields['currency'],
            ...$fields['productName'], ...$fields['productCount'], ...$fields['productPrice'],
        ]);

        // retry(1): Laravel retries a ConnectionException too, and a timeout says nothing about
        // whether WayForPay already debited the card. A repeated CHARGE on the same orderReference
        // is not guaranteed to be rejected, so a second attempt can be a second debit.
        $data = Http::timeout(15)->retry(1)->post(self::API_URL, $fields)->throw()->json();

        return new PaymentResult(externalId: $data['orderReference'] ?? null, raw: $data);
    }

    /** No gateway-side revocation endpoint documented for a standalone recToken — local-only. */
    public function detachPaymentMethod(PaymentMethod $method): void
    {
        $method->delete();

        PaymentMethodDetached::dispatch($method);
    }

    public function checkStatus(Payment $payment): WebhookResult
    {
        $data = Http::timeout(15)->retry(2, 200)->post(self::API_URL, [
            'transactionType' => 'CHECK_STATUS',
            'merchantAccount' => $this->merchantAccount(),
            'orderReference' => (string) $payment->id,
            'merchantSignature' => $this->sign([$this->merchantAccount(), (string) $payment->id]),
            'apiVersion' => 1,
        ])->throw()->json();

        $status = match ($data['transactionStatus'] ?? null) {
            'Approved' => PaymentStatus::Paid,
            'Declined' => PaymentStatus::Failed,
            'Expired' => PaymentStatus::Canceled,
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

        if (! $payment->transitionTo($status, $this->feeFrom($data['fee'] ?? null, decimal: true))) {
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
            externalId: (string) $payment->id,
            raw: $data,
        );
    }

    /**
     * No introspection endpoint — probes with CHECK_STATUS for a nonexistent order. Live-verified
     * discriminators: reasonCode 1127 "Order Not Found" = credentials/signature fine; 1113
     * "Invalid signature" (or anything else) = they aren't.
     */
    public function healthCheck(): \Fomvasss\Billing\DTO\GatewayHealth
    {
        return $this->probeHealth(function () {
            $reference = 'billing-health-probe';

            $data = Http::timeout(15)->retry(1)->post(self::API_URL, [
                'transactionType' => 'CHECK_STATUS',
                'merchantAccount' => $this->merchantAccount(),
                'orderReference' => $reference,
                'merchantSignature' => $this->sign([$this->merchantAccount(), $reference]),
                'apiVersion' => 1,
            ])->throw()->json();

            if ((int) ($data['reasonCode'] ?? 0) === 1127) {
                return 'credentials accepted';
            }

            throw new BillingException(($data['reason'] ?? 'unexpected response') . ' (reasonCode ' . ($data['reasonCode'] ?? '?') . ')');
        });
    }

    public static function label(): string
    {
        return 'WayForPay';
    }

    public static function credentialFields(): array
    {
        return [
            ['name' => 'merchant_account', 'type' => 'text', 'secret' => false, 'help' => 'merchantAccount з кабінету WayForPay'],
            ['name' => 'merchant_domain', 'type' => 'text', 'secret' => false, 'help' => 'Домен сайту, зареєстрований у мерчант-акаунті'],
            ['name' => 'secret_key', 'type' => 'text', 'secret' => true, 'help' => 'Секретний ключ для HMAC-підпису'],
            ['name' => 'link_ttl_minutes', 'type' => 'number', 'secret' => false, 'help' => 'TTL посилання на оплату, хв (orderLifetime), дефолт 1440 (доба)'],
        ];
    }

    public static function supportedCurrencies(): array
    {
        return ['UAH', 'USD', 'EUR'];
    }

    /** HMAC-MD5 over the given fields joined with ";" — same formula used both directions (request + CHECK_STATUS). */
    protected function sign(array $fields): string
    {
        return hash_hmac('md5', implode(';', $fields), $this->secretKey());
    }

    protected function formatAmount(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }

    /** productName[]/productCount[]/productPrice[] are required even for a single-line charge. */
    protected function products(array $receiptItems, Payment $payment, ChargeOptions $options): array
    {
        if ($receiptItems === []) {
            return [
                'name' => [$options->description ?? "Payment #{$payment->id}"],
                'count' => [1],
                'price' => [$this->formatAmount($payment->amount)],
            ];
        }

        return [
            'name' => array_column($receiptItems, 'name'),
            'count' => array_column($receiptItems, 'qty'),
            'price' => array_map(fn (array $item) => $this->formatAmount($item['unitAmount']), $receiptItems),
        ];
    }

    protected function merchantAccount(): string
    {
        return $this->credentials['merchant_account'] ?? throw new BillingException('WayForPay: credential "merchant_account" is missing.');
    }

    protected function merchantDomainName(): string
    {
        return $this->credentials['merchant_domain'] ?? throw new BillingException('WayForPay: credential "merchant_domain" is missing.');
    }

    protected function secretKey(): string
    {
        return $this->credentials['secret_key'] ?? throw new BillingException('WayForPay: credential "secret_key" is missing.');
    }

    /**
     * The auto-attach path handleWebhook() calls whenever an approved payment's callback carries a
     * recToken — no attachPaymentMethod() call needed. Not routed through WebhookResultDispatcher
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
            $payload['recToken'],
            isset($payload['cardPan']) ? substr($payload['cardPan'], -4) : null,
            $payload['cardType'] ?? null,
        );

        // Direct dispatch runs BEFORE ProcessWebhookJob's dedup claim — wasRecentlyCreated keeps a
        // re-delivered callback from firing PaymentMethodAttached again (same as LiqPay/Hutko).
        if ($method->wasRecentlyCreated) {
            PaymentMethodAttached::dispatch($method);
        }
    }

    protected function customerId(string $billableType, string $billableId): string
    {
        return md5($billableType . ':' . $billableId);
    }
}
