<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Stripe;

use Illuminate\Http\Request;
use Fomvasss\Billing\Contracts\SignatureValidator;

/**
 * Stripe-Signature: "t={timestamp},v1={hmac},v0={hmac}..." — HMAC-SHA256("{t}.{payload}", secret),
 * 5-minute tolerance against replay, constant-time comparison. Confirmed from the official docs
 * (docs.stripe.com/webhooks/signatures) "Verify manually" section.
 */
class StripeSignatureValidator implements SignatureValidator
{
    protected const TOLERANCE_SECONDS = 300;

    public function isValid(Request $request): bool
    {
        $header = $request->header('Stripe-Signature', '');
        $payload = $request->getContent();

        if ($header === '' || $payload === '') {
            return false;
        }

        [$timestamp, $signatures] = $this->parseHeader($header);

        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $secret = config('billing.gateways.stripe.webhook_secret', '');
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: int|null, 1: string[]} */
    protected function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
