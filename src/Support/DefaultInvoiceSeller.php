<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Contracts\InvoiceSellerContract;
use Fomvasss\Billing\DTO\BillingDetails;

/**
 * The gateway's `seller` (read through CredentialResolverContract, so per-tenant credentials from a
 * database can carry it too), falling back to config('billing.invoices.seller').
 */
final class DefaultInvoiceSeller implements InvoiceSellerContract
{
    public function seller(?string $gateway, ?string $tenantId): BillingDetails
    {
        $own = $gateway === null ? null : (app(CredentialResolverContract::class)->resolve($gateway, $tenantId)['seller'] ?? null);

        return BillingDetails::fromArray(is_array($own) && $own !== [] ? $own : (array) config('billing.invoices.seller', []));
    }
}
