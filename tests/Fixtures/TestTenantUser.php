<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Fixtures;

/** A billable that actually belongs to a tenant — TestUser's tenantId() is the null default. */
class TestTenantUser extends TestUser
{
    public function tenantId(): ?string
    {
        return 'tenant-1';
    }
}
