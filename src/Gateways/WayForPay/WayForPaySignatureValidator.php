<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\WayForPay;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Support\WebhookPayload;
use Illuminate\Http\Request;
use Fomvasss\Billing\Contracts\SignatureValidator;
use Fomvasss\Billing\Support\WebhookTenant;

/**
 * merchantAccount;orderReference;amount;currency;authCode;cardPan;transactionStatus;reasonCode,
 * HMAC-MD5 with the merchant secret key — confirmed from the official PHP SDK's ServiceUrlHandler.
 *
 * Fields are read via WebhookPayload, never $request->input(): WayForPay POSTs its serviceUrl
 * callback as RAW JSON under a form content type, so the framework-parsed body is one garbled key.
 */
class WayForPaySignatureValidator implements SignatureValidator
{
    public function isValid(Request $request): bool
    {
        $secret = app(CredentialResolverContract::class)->resolve('wayforpay', WebhookTenant::fromRequest($request))['secret_key'] ?? null;

        // Fail closed — an unconfigured gateway's webhook route must reject, not verify against ''.
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $payload = WebhookPayload::fromRequest($request);

        $signature = $payload['merchantSignature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $fields = [
            $payload['merchantAccount'] ?? '',
            $payload['orderReference'] ?? '',
            $payload['amount'] ?? '',
            $payload['currency'] ?? '',
            $payload['authCode'] ?? '',
            $payload['cardPan'] ?? '',
            $payload['transactionStatus'] ?? '',
            $payload['reasonCode'] ?? '',
        ];

        $expected = hash_hmac('md5', implode(';', $fields), $secret);

        return hash_equals($expected, $signature);
    }
}
