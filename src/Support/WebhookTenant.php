<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Illuminate\Http\Request;

/**
 * How a webhook says which merchant account it belongs to, for apps whose CredentialResolverContract
 * returns different credentials per tenant. There is no other way round it: the secret is needed to
 * verify the signature, so the tenant has to be known BEFORE the payload can be trusted — the hint
 * therefore rides in the callback URL, put there at charge time via
 * `ChargeOptions::$webhookUrlParams` (`['tenant' => $id]`).
 *
 * Untrusted by construction, and safe anyway: it only selects which secret to verify against. A
 * forged hint picks the wrong secret and the signature check fails, exactly as it should. Apps on
 * the default resolver (config credentials) can ignore all of this — it ignores $tenantId.
 */
final class WebhookTenant
{
    public const QUERY_KEY = 'tenant';

    public static function fromRequest(Request $request): ?string
    {
        $tenant = $request->query(self::QUERY_KEY);

        return is_string($tenant) && $tenant !== '' ? $tenant : null;
    }
}
