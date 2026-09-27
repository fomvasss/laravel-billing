<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Illuminate\Http\Request;

/**
 * One per gateway, registered alongside extend() via BillingManager::registerWebhook(). Each
 * driver resolves its own secret internally (config or CredentialResolverContract) — there's no
 * shared "signing_secret" concept across gateways, the formats aren't compatible with each other.
 *
 * Resolve credentials under the name the webhook came in on — `$request->route('gateway')` — not a
 * hardcoded one: the same class can be registered twice under different names (two merchant
 * accounts of one gateway), and each name has its own secret.
 */
interface SignatureValidator
{
    public function isValid(Request $request): bool;
}
