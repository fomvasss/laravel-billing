<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\LiqPay;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Support\WebhookPayload;
use Illuminate\Http\Request;
use Fomvasss\Billing\Contracts\SignatureValidator;

/**
 * Same formula LiqPayGateway::sign() uses to sign outgoing requests, applied to the incoming
 * `data` field: base64_encode(sha1(private_key . data . private_key, true)) — confirmed from the
 * official PHP SDK source.
 */
class LiqPaySignatureValidator implements SignatureValidator
{
    public function isValid(Request $request): bool
    {
        $privateKey = app(CredentialResolverContract::class)->resolve('liqpay', null)['private_key'] ?? null;

        // Fail closed: every built-in gateway's webhook route exists even when the gateway is not
        // configured — with no key an attacker could compute the "signature" themselves.
        if (! is_string($privateKey) || $privateKey === '') {
            return false;
        }

        $payload = WebhookPayload::fromRequest($request);
        $data = $payload['data'] ?? null;
        $signature = $payload['signature'] ?? null;

        if (! is_string($data) || ! is_string($signature) || $data === '' || $signature === '') {
            return false;
        }

        $expected = base64_encode(sha1($privateKey . $data . $privateKey, true));

        return hash_equals($expected, $signature);
    }
}
