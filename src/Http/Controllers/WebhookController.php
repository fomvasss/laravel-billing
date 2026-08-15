<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Http\Controllers;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Jobs\ProcessWebhookJob;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * One route for every gateway (POST /billing/webhooks/{gateway}) — resolved through
 * BillingManager's registries at request time, not a per-gateway route/config. See
 * "Webhook pipeline" in the package plan for why this replaced the earlier
 * spatie/laravel-webhook-client-based design (that package assumes a static, known-upfront list
 * of sources, which never fit gateways registered dynamically via extend()).
 */
class WebhookController extends Controller
{
    public function handle(Request $request, string $gateway): Response
    {
        $manager = app(BillingManager::class);

        $validator = app($manager->signatureValidatorFor($gateway));

        if (! $validator->isValid($request)) {
            abort(403, 'Invalid webhook signature.');
        }

        $webhookCall = BillingWebhookCall::storeWebhook($gateway, $request);

        try {
            ProcessWebhookJob::dispatch($webhookCall);
        } catch (\Throwable $exception) {
            $webhookCall->saveException($exception);

            throw $exception;
        }

        return app($manager->responderFor($gateway))->respond($request);
    }
}
