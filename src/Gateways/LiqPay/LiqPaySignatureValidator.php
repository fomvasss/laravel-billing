<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\LiqPay;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/**
 * Same formula LiqPayGateway::sign() uses to sign outgoing requests, applied to the incoming
 * `data` field: base64_encode(sha1(private_key . data . private_key, true)) — confirmed from the
 * official PHP SDK source.
 */
class LiqPaySignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $data = $request->input('data');
        $signature = $request->input('signature');

        if (! is_string($data) || ! is_string($signature) || $data === '' || $signature === '') {
            return false;
        }

        $privateKey = config('billing.gateways.liqpay.private_key', '');

        $expected = base64_encode(sha1($privateKey . $data . $privateKey, true));

        return hash_equals($expected, $signature);
    }
}
