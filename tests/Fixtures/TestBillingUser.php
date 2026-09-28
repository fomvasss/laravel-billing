<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Fixtures;

use Fomvasss\Billing\Contracts\HasBillingDetails;
use Fomvasss\Billing\DTO\BillingDetails;

/** A billable that knows its own details for documents — read at issue time, then frozen. */
class TestBillingUser extends TestUser implements HasBillingDetails
{
    public function billingDetails(): BillingDetails
    {
        return new BillingDetails(name: $this->name, taxId: '1234567890', email: 'buyer@example.test');
    }
}
