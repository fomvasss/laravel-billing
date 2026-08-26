<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Rolls the allowance over for prices whose quota renews on its own cadence
 * (price.quota_interval) — "pay for a year, get included_units every month".
 *
 * Unlike the other schedulers here this one deliberately does NOT skip provider-managed rows
 * (external_id): a quota reset touches no gateway and no money. The allowance is the package's
 * own bookkeeping either way, so a Stripe-managed subscription still needs its monthly counter
 * zeroed by someone.
 *
 * Only running subscriptions are picked up. A paused or ended one keeps its boundary in the past
 * and gets its fresh allowance the moment it resumes — granting quota to a subscription nobody is
 * paying for would be a quiet giveaway.
 */
class ResetUsageQuotasCommand extends Command
{
    protected $signature = 'billing:reset-usage-quotas';

    protected $description = 'Reset current_usage for subscriptions whose own quota cycle has ended';

    public function handle(): int
    {
        $count = 0;

        Subscription::query()
            ->with('price')
            ->whereIn('status', [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->whereNotNull('quota_period_ends_at')
            ->where('quota_period_ends_at', '<=', now())
            ->chunkById(200, function ($subscriptions) use (&$count) {
                foreach ($subscriptions as $subscription) {
                    $count += (int) $subscription->resetUsageQuota();
                }
            });

        $this->info("Reset the usage quota on {$count} subscription(s).");

        return self::SUCCESS;
    }
}
