<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Contracts\WebhookResponder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultWebhookResponder implements WebhookResponder
{
    public function respond(Request $request): Response
    {
        return response()->json(['message' => 'ok']);
    }
}
