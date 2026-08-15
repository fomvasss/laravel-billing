<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Contracts\CredentialResolverContract;

/**
 * Zero-DB default: tenantId is ignored, credentials come straight from config('billing.gateways.*').
 * Bind your own CredentialResolverContract in your app's provider to source dynamic per-tenant creds.
 */
final class DefaultCredentialResolver implements CredentialResolverContract
{
    public function resolve(string $gateway, ?string $tenantId): array
    {
        return (array) config("billing.gateways.{$gateway}", []);
    }
}
