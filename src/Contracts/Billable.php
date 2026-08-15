<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

/**
 * Whoever pays (User/Organization/Team). tenantId() is called by the package (e.g. before
 * resolving gateway credentials), so it must be public — unlike the internal `Concerns\Billable`
 * default, which a model pulls in via `use` to satisfy this contract without writing it by hand.
 */
interface Billable
{
    public function tenantId(): ?string;
}
