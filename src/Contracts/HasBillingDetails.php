<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\BillingDetails;

/**
 * Optional, on your Billable: the customer's details for the documents the package issues. Read
 * once, at issue time — the invoice keeps its own copy. A buyer passed to issueInvoice() wins.
 */
interface HasBillingDetails
{
    public function billingDetails(): BillingDetails;
}
