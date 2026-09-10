<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Concerns;

use Fomvasss\Billing\Contracts\Billable;

/**
 * Fills `tenant_id` from the row's billable when the caller left it empty.
 *
 * The column is derived, not authored: a caller that already set `billable_*` has said everything
 * needed, and asking it to restate the tenant is a second source for one truth — one the package's
 * own paths (renewals, refunds) fill while a hand-written `$billable->payments()->create()` does
 * not. The result was silent: no error, just rows invisible to every tenant-scoped query.
 *
 * A tenant_id the caller passed itself is never overwritten — including an explicit one that
 * differs from the billable's, which is a legitimate cross-tenant record.
 */
trait DerivesTenantId
{
    public static function bootDerivesTenantId(): void
    {
        static::creating(function (self $model) {
            // Only when it is missing: the billable is a morph, so filling it costs a query, and
            // the common path (the package's own writes) already carries the value.
            if ($model->tenant_id !== null || $model->billable_type === null) {
                return;
            }

            $billable = $model->billable;

            if ($billable instanceof Billable) {
                $model->tenant_id = $billable->tenantId();
            }
        });
    }
}
