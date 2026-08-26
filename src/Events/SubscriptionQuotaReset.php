<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A quota cycle rolled over: current_usage is back to zero and quota_period_ends_at points at the
 * next boundary. Only fires for prices with a quota cycle of their own (price.quota_interval) —
 * when the quota lives on the billing period, a paid renewal already says the same thing through
 * SubscriptionRenewed.
 *
 * The hook for "your monthly allowance is back" notices, and for consumers that mirror the
 * allowance in their own ledger.
 */
class SubscriptionQuotaReset
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Subscription $subscription) {}
}
