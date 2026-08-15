<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\WayForPay;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/**
 * merchantAccount;orderReference;amount;currency;authCode;cardPan;transactionStatus;reasonCode,
 * HMAC-MD5 with the merchant secret key — confirmed from the official PHP SDK's ServiceUrlHandler.
 */
class WayForPaySignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $signature = $request->input('merchantSignature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $fields = [
            $request->input('merchantAccount', ''),
            $request->input('orderReference', ''),
            $request->input('amount', ''),
            $request->input('currency', ''),
            $request->input('authCode', ''),
            $request->input('cardPan', ''),
            $request->input('transactionStatus', ''),
            $request->input('reasonCode', ''),
        ];

        $expected = hash_hmac('md5', implode(';', $fields), config('billing.gateways.wayforpay.secret_key', ''));

        return hash_equals($expected, $signature);
    }
}
