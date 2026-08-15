<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\WayForPay;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Contracts\WebhookResponder;
use Fomvasss\Billing\Support\WebhookPayload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WayForPay is the one built-in gateway that doesn't accept a bare HTTP 200 — it retries the
 * callback for up to 4 days until it gets back a signed `{orderReference, status: "accept", time,
 * signature}` JSON body (confirmed from the official PHP SDK's ServiceUrlHandler::getSuccessResponse()).
 * Registered as this gateway's responder in BillingManager::registerWebhook().
 *
 * orderReference comes via WebhookPayload — the callback body is raw JSON under a form content
 * type, see WayForPaySignatureValidator.
 */
class WayForPayWebhookResponder implements WebhookResponder
{
    public function respond(Request $request): Response
    {
        $payload = WebhookPayload::fromRequest($request);

        $orderReference = (string) ($payload['orderReference'] ?? '');
        $time = now()->timestamp;

        $secret = app(CredentialResolverContract::class)->resolve('wayforpay', null)['secret_key'] ?? '';

        $signature = hash_hmac(
            'md5',
            implode(';', [$orderReference, 'accept', $time]),
            $secret
        );

        return response()->json([
            'orderReference' => $orderReference,
            'status' => 'accept',
            'time' => $time,
            'signature' => $signature,
        ]);
    }
}
