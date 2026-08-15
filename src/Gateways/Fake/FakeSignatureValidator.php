<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Fake;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/** No real bank, no real signature to check — this driver only exists in local/testing anyway. */
class FakeSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        return true;
    }
}
