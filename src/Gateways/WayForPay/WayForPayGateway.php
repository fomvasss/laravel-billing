<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\WayForPay;

use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Gateways\AbstractGateway;
use Fomvasss\Billing\Models\Payment;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * https://secure.wayforpay.com/pay (checkout form) + https://api.wayforpay.com/api (server-server,
 * CHECK_STATUS). Verified against wiki.wayforpay.com "Accept payment (Purchase)"/"Checking of
 * payment status" and the official PHP SDK (github.com/wayforpay/php-sdk) — signature and
 * acknowledgment-response formats were NOT in dropshop's reference at the level of detail needed
 * (see WayForPayWebhookResponder).
 *
 * `amount` — decimal major units (e.g. "100.00" UAH), same as LiqPay, not minor units.
 *
 * No RefundsPayments in v1 — WayForPay's refund endpoint (transactionType: REFUND) needs the same
 * host2host access tier as CHECK_STATUS/Charge, not confirmed available on a standard merchant
 * account; adding the interface without being able to verify the exact request shape against docs
 * would be guessing, not porting. Add when a real credential is available to test against.
 */
class WayForPayGateway extends AbstractGateway implements ChecksPaymentStatus
{
    protected const CHECKOUT_URL = 'https://secure.wayforpay.com/pay';

    protected const API_URL = 'https://api.wayforpay.com/api';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $products = $this->products($options->receiptItems, $payment, $options);

        $orderDate = now()->timestamp;

        $fields = array_filter([
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

        return new PaymentResult(form: ['action' => self::CHECKOUT_URL, 'fields' => $fields]);
    }

    public function handleWebhook(WebhookCall $webhookCall): WebhookResult
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
}
