<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Hutko;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/**
 * Same algorithm as HutkoGateway::sign(): drop `signature` from the body, ksort the rest, prepend
 * the secret key, pipe-join, SHA1 — confirmed from WC_Oplata_API::validateRequest()/getSignature().
 */
class HutkoSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $signature = $request->input('signature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $fields = $request->except(['signature', 'response_signature_string']);
        $fields = array_filter($fields, static fn ($value) => $value !== '' && $value !== null);
        ksort($fields);

        $secretKey = config('billing.gateways.hutko.secret_key', '');

        $string = $secretKey;

        foreach ($fields as $value) {
            $string .= '|' . (is_array($value) ? json_encode($value) : $value);
        }

        return hash_equals(sha1($string), $signature);
    }
}
