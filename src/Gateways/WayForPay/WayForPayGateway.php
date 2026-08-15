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
class WayForPayGateway extends AbstractGateway implements ChecksPaymentStatus, TokenizesPaymentMethod
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
            'currency' => $payment->currency_code,
            'productName' => $products['name'],
            'productCount' => $products['count'],
            'productPrice' => $products['price'],
            'clientEmail' => $options->customerEmail,
            'returnUrl' => $this->successUrl($options),
            'serviceUrl' => $this->webhookUrl($options),
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

        return new PaymentResult(url: $data['url']);
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $payload = $webhookCall->payload;

        $payment = Payment::findOrFail($payload['orderReference']);

        $status = match ($payload['transactionStatus'] ?? null) {
            'Approved' => PaymentStatus::Paid,
            'Declined', 'Expired' => PaymentStatus::Failed,
            // Pending/InProcessing/RefundInProcessing/Refunded/Voided — not a Payment-status
            // transition here, same "recognized, no consumer yet" reasoning as LiqPay's 'reversed'
            default => null,
        };

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

        $payment->update(['status' => $status]);

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: $status === PaymentStatus::Paid ? 'succeeded' : 'failed',
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
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, array $options = []): PaymentResult
    {
        $orderDate = now()->timestamp;
        $amount = $this->formatAmount($payment->amount);
        $products = $this->products([], $payment, new ChargeOptions(description: $options['description'] ?? null));

        $fields = array_filter([
            'transactionType' => 'CHARGE',
            'merchantAccount' => $this->merchantAccount(),
            'merchantAuthType' => 'SimpleSignature',
            'merchantDomainName' => $this->merchantDomainName(),
            'merchantTransactionType' => 'SALE',
            'merchantTransactionSecureType' => 'NON3DS',
            'orderReference' => (string) $payment->id,
            'orderDate' => $orderDate,
            'amount' => $amount,
            'currency' => $payment->currency_code,
            'productName' => $products['name'],
            'productCount' => $products['count'],
            'productPrice' => $products['price'],
            'recToken' => $method->external_id,
            'serviceUrl' => route('billing.webhook', ['gateway' => $this->gatewayName]),
            'apiVersion' => 1,
        ], static fn ($value) => $value !== null && $value !== '');

        $fields['merchantSignature'] = $this->sign([
            $fields['merchantAccount'], $fields['merchantDomainName'], $fields['orderReference'],
            $fields['orderDate'], $fields['amount'], $fields['currency'],
            ...$fields['productName'], ...$fields['productCount'], ...$fields['productPrice'],
        ]);

        $data = Http::timeout(15)->retry(2, 200)->post(self::API_URL, $fields)->throw()->json();

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
            'Declined', 'Expired' => PaymentStatus::Failed,
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        $payment->update(['status' => $status]);

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: $status === PaymentStatus::Paid ? 'succeeded' : 'failed',
            payment: $payment,
            externalId: (string) $payment->id,
            raw: $data,
        );
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

        PaymentMethodAttached::dispatch($method);
    }

    protected function customerId(string $billableType, string $billableId): string
    {
        return md5($billableType . ':' . $billableId);
    }
}
