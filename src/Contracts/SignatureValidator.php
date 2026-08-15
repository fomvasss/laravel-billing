<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Illuminate\Http\Request;

/**
 * One per gateway, registered alongside extend() via BillingManager::registerWebhook(). Each
 * driver resolves its own secret internally (config or CredentialResolverContract) — there's no
 * shared "signing_secret" concept across gateways, the formats aren't compatible with each other.
 */
interface SignatureValidator
{
    public function isValid(Request $request): bool;
}
