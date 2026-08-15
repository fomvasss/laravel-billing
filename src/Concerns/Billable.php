<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Concerns;

/**
 * Default implementation of Contracts\Billable — mirrors AiTask::tenantId() in laravel-ai-tasks.
 * Returns null (no tenant) by default; override in the model that `use`s this trait if
 * multi-tenancy is needed (e.g. `return $this->organization_id;`).
 */
trait Billable
{
    public function tenantId(): ?string
    {
        return null;
    }
}
