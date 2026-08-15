<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Monobank;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/**
 * ECDSA over the raw request body, per the official docs (webhook.md): cache the public key,
 * retry with a fresh one only when verification with the cached key fails (never fetch per webhook
 * — the docs explicitly call that out).
 *
 * Single, config-level X-Token — this driver doesn't yet resolve credentials per tenant here (see
 * "Кроки реалізації" п.5 note): the incoming webhook has no tenant identifier of its own to look
 * one up by before the payload is verified. Fine for a single-merchant setup; a genuinely
 * multi-merchant Monobank integration needs a per-tenant webhook URL to know which token to use
 * before verifying — not built until a real consumer needs it.
 */
class MonobankSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $signature = base64_decode($request->header('X-Sign', ''), true);
        $body = $request->getContent();

        if ($signature === false || $body === '') {
            return false;
        }

        if ($this->verify($this->publicKey(), $body, $signature)) {
            return true;
        }

        // Key may have rotated — refetch once and retry before rejecting.
        return $this->verify($this->publicKey(forceRefresh: true), $body, $signature);
    }

    protected function verify(string $base64Key, string $body, string $signature): bool
    {
        $publicKeyResource = openssl_pkey_get_public(base64_decode($base64Key));

        if ($publicKeyResource === false) {
            return false;
        }

        return openssl_verify($body, $signature, $publicKeyResource, OPENSSL_ALGO_SHA256) === 1;
    }

    protected function publicKey(bool $forceRefresh = false): string
    {
        if ($forceRefresh) {
            Cache::forget('billing:monobank:pubkey');
        }

        return Cache::remember('billing:monobank:pubkey', now()->addWeek(), fn () => Http::withHeaders([
            'X-Token' => config('billing.gateways.monobank.token'),
        ])->get('https://api.monobank.ua/api/merchant/pubkey')->throw()->json('key'));
    }
}
