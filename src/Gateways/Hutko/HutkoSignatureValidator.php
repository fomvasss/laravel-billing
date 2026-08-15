<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Hutko;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Support\WebhookPayload;
use Illuminate\Http\Request;
use Fomvasss\Billing\Contracts\SignatureValidator;

/**
 * Same algorithm as HutkoGateway::sign(): drop `signature` from the body, ksort the rest, prepend
 * the secret key, pipe-join, SHA1 — confirmed from WC_Oplata_API::validateRequest()/getSignature().
 *
 * Fields come from WebhookPayload (body only), never $request->all() — the callback URL may carry
 * webhookUrlParams query extras, and merging those into a payload-wide signature scheme would
 * break verification for every callback.
 */
class HutkoSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request): bool
    {
        $secretKey = app(CredentialResolverContract::class)->resolve('hutko', null)['secret_key'] ?? null;

        // Fail closed — an unconfigured gateway's webhook route must reject, not verify against ''.
        if (! is_string($secretKey) || $secretKey === '') {
            return false;
        }

        $payload = WebhookPayload::fromRequest($request);

        $signature = $payload['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        unset($payload['signature'], $payload['response_signature_string']);
        $fields = array_filter($payload, static fn ($value) => $value !== '' && $value !== null);
        ksort($fields);

        $string = $secretKey;

        foreach ($fields as $value) {
            $string .= '|' . (is_array($value) ? json_encode($value) : $value);
        }

        return hash_equals(sha1($string), $signature);
    }
}
