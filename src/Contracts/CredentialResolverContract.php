<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

/**
 * The package ships no table for this — only the contract. Default binding (Support\DefaultCredentialResolver)
 * reads config('billing.gateways.{$gateway}') and ignores $tenantId. Bind your own implementation
 * to source dynamic per-tenant credentials from wherever your project already stores them.
 */
interface CredentialResolverContract
{
    public function resolve(string $gateway, ?string $tenantId): array;
}
