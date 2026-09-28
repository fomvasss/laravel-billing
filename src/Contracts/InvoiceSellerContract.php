<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\BillingDetails;

/**
 * Who issues the documents. Default (Support\DefaultInvoiceSeller): the gateway's own `seller`
 * block when it has one — a merchant account that belongs to a different legal entity or brand —
 * otherwise config('billing.invoices.seller'). Bind your own for anything else (per tenant from a
 * database, per brand), the same way as CredentialResolverContract.
 */
interface InvoiceSellerContract
{
    /** $gateway is null for a document not tied to a gateway (a manual bank-transfer invoice). */
    public function seller(?string $gateway, ?string $tenantId): BillingDetails;
}
