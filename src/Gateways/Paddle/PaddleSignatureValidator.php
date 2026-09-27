<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Paddle;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Contracts\SignatureValidator;
use Fomvasss\Billing\Support\WebhookTenant;
use Illuminate\Http\Request;

/**
 * Paddle-Signature: "ts={timestamp};h1={hmac}" — HMAC-SHA256("{ts}:{raw body}", the notification
 * destination's secret key). More than one h1 arrives while a secret is being rotated; any match
 * is enough. developer.paddle.com/webhooks/about/signature-verification.
 *
 * Paddle's SDKs reject a timestamp more than 5 seconds off; this allows the same 5 minutes as
 * StripeSignatureValidator — a replay inside the window is dropped by the dedup claim anyway,
 * while 5 seconds would turn ordinary clock drift into rejected webhooks.
 */
class PaddleSignatureValidator implements SignatureValidator
{
    protected const TOLERANCE_SECONDS = 300;

    public function isValid(Request $request): bool
    {
        $secret = app(CredentialResolverContract::class)->resolve($request->route('gateway') ?? 'paddle', WebhookTenant::fromRequest($request))['webhook_secret'] ?? null;

        // Fail closed — an unconfigured gateway's webhook route must reject, not verify against ''.
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $header = $request->header('Paddle-Signature', '');
        $payload = $request->getContent();

        if ($header === '' || $payload === '') {
            return false;
        }

        [$timestamp, $signatures] = $this->parseHeader($header);

        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}:{$payload}", $secret);

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

        foreach (explode(';', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($key === 'ts') {
                $timestamp = (int) $value;
            } elseif ($key === 'h1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
