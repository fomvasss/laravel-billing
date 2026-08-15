<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\WayForPay;

use Fomvasss\Billing\Contracts\WebhookResponder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WayForPay is the one built-in gateway that doesn't accept a bare HTTP 200 — it retries the
 * callback for up to 4 days until it gets back a signed `{orderReference, status: "accept", time,
 * signature}` JSON body (confirmed from the official PHP SDK's ServiceUrlHandler::getSuccessResponse()).
 * Registered as this gateway's responder in BillingManager::registerWebhook().
 */
class WayForPayWebhookResponder implements WebhookResponder
{
    public function respond(Request $request): Response
    {
        $orderReference = (string) $request->input('orderReference', '');
        $time = now()->timestamp;

        $signature = hash_hmac(
            'md5',
            implode(';', [$orderReference, 'accept', $time]),
            config('billing.gateways.wayforpay.secret_key', '')
        );

        return response()->json([
            'orderReference' => $orderReference,
            'status' => 'accept',
            'time' => $time,
            'signature' => $signature,
        ]);
    }
}
