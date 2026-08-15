<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Monobank;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Contracts\SignatureValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * ECDSA over the raw request body, per the official docs (webhook.md): cache the public key,
 * retry with a fresh one only when verification with the cached key fails (never fetch per webhook
 * — the docs explicitly call that out).
 *
 * Credentials come through CredentialResolverContract with tenantId=null (same as the other
 * validators) — the incoming webhook has no tenant identifier of its own to look one up by before
 * the payload is verified. Fine for a single-merchant setup; a genuinely multi-merchant Monobank
 * integration needs a per-tenant webhook URL to know which token to use before verifying — not
 * built until a real consumer needs it.
 */
class MonobankSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request): bool
    {
        $token = app(CredentialResolverContract::class)->resolve('monobank', null)['token'] ?? null;

        // Fail closed — without a token there's no way to fetch the pubkey to verify against.
        if (! is_string($token) || $token === '') {
            return false;
        }

        $signature = base64_decode($request->header('X-Sign', ''), true);
        $body = $request->getContent();

        if ($signature === false || $body === '') {
            return false;
        }

        if ($this->verify($this->publicKey($token), $body, $signature)) {
            return true;
        }

        // Key may have rotated — refetch once and retry before rejecting. Throttled: without the
        // lock, a flood of garbage-signature requests would hit Monobank's API on every single one.
        if (! Cache::add('billing:monobank:pubkey:refetch-lock', true, 300)) {
            return false;
        }

        return $this->verify($this->publicKey($token, forceRefresh: true), $body, $signature);
    }

    protected function verify(string $base64Key, string $body, string $signature): bool
    {
        $publicKeyResource = openssl_pkey_get_public(base64_decode($base64Key));

        if ($publicKeyResource === false) {
            return false;
        }

        return openssl_verify($body, $signature, $publicKeyResource, OPENSSL_ALGO_SHA256) === 1;
    }

    protected function publicKey(string $token, bool $forceRefresh = false): string
    {
        if ($forceRefresh) {
            Cache::forget('billing:monobank:pubkey');
        }

        return Cache::remember('billing:monobank:pubkey', now()->addWeek(), fn () => Http::withHeaders([
            'X-Token' => $token,
        ])->get('https://api.monobank.ua/api/merchant/pubkey')->throw()->json('key'));
    }
}
