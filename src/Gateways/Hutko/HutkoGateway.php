<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Hutko;

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
 * https://pay.hutko.org/api/ — no dropshop reference existed for this one (see the package plan's
 * "Hutko" section); verified instead against the official WooCommerce plugin source
 * (github.com/hutko-service/hutko-payment, class-wc-oplata-api.php +
 * abstract-wc-oplata-payment-gateway.php) — the only concrete, current source found; docs.hutko.org
 * itself 404s on direct fetch.
 *
 * Uses Scheme B (`checkout/url`, host-to-host — merchant gets a checkout_url back), not Scheme A's
 * auto-submit redirect form, for consistency with Monobank/Stripe's PaymentResult::$url pattern.
 *
 * `amount` — minor units, confirmed from the plugin (`(int) round($order->get_total() * 100)`).
 *
 * Everything here (field names, signature algorithm, order_status vocabulary) is what a real
 * WordPress plugin sends/receives in production, not docs prose — the most reliable source
 * available without a live merchant account to test against.
 */
class HutkoGateway extends AbstractGateway
{
    protected const BASE_URL = 'https://pay.hutko.org/api/';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        $data = $this->request('checkout/url', array_filter([
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

    public function handleWebhook(WebhookCall $webhookCall): WebhookResult
    {
        $payload = $webhookCall->payload;

        // Reversals/refunds surface here too (reversal_amount present, or tran_type=reverse) — the
        // plugin ignores these silently rather than mutating the original order; same "explicit
        // refund is the supported path" reasoning as the other built-in drivers.
        if (! empty($payload['reversal_amount']) || ($payload['tran_type'] ?? null) === 'reverse') {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $payload);
        }

        $payment = Payment::findOrFail((int) $payload['order_id']);

        $status = match ($payload['order_status'] ?? null) {
            'approved' => PaymentStatus::Paid,
            'declined' => PaymentStatus::Failed,
            'expired' => PaymentStatus::Canceled,
            // created/processing — not terminal
            default => null,
        };

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
}
