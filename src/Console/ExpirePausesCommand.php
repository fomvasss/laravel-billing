<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Resumes subscriptions paused with an $until (Subscription::pause()) whose pause_ends_at has
 * passed. Indefinite pauses (pause_ends_at null) are never touched here — only an explicit
 * resume() ends those. Goes through resume() itself, not a bare update(), so SubscriptionResumed
 * fires the same way it would for a manual resume.
 */
class ExpirePausesCommand extends Command
{
    protected $signature = 'billing:expire-pauses';

    protected $description = 'Resume subscriptions whose scheduled pause has ended';

    public function handle(): int
    {
        $count = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Paused)
            ->whereNotNull('pause_ends_at')
            ->where('pause_ends_at', '<=', now())
            // Provider-managed subscriptions are never touched by the package's own schedulers.
            ->whereNull('external_id')
            ->chunkById(200, function ($subscriptions) use (&$count) {
                foreach ($subscriptions as $subscription) {
                    $subscription->resume();
                    $count++;
                }
            });

        $this->info("Resumed {$count} paused subscription(s).");

        return self::SUCCESS;
    }
}
