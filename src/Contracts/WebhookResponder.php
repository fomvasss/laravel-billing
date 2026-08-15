<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Most gateways are happy with a bare 200 (DefaultWebhookResponder). Register a custom one via
 * BillingManager::registerWebhook() when the bank requires a specific acknowledgment body —
 * WayForPay does (signed {orderReference, status: "accept", time, signature}), see
 * WayForPayWebhookResponder for a worked example.
 */
interface WebhookResponder
{
    public function respond(Request $request): Response;
}
