<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Subscription;
use Illuminate\Console\Command;

/**
 * No event dispatched on purpose — the consumer just reads `status` to decide what to block (see
 * the itschats trial case in "Бізнес-модель itschats" in the package plan). TrialWillEnd (the
 * reminder-before-expiry event) is a separate, consumer-driven concern, not this command's job.
 */
class ExpireTrialsCommand extends Command
{
    protected $signature = 'billing:expire-trials';

    protected $description = 'Mark subscriptions whose trial ended without converting to paid as ended';

    public function handle(): int
    {
        $count = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->update(['status' => SubscriptionStatus::Ended]);

        $this->info("Expired {$count} trial subscription(s).");

        return self::SUCCESS;
    }
}
