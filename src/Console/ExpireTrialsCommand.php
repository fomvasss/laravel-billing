<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\TrialWillEnd;
use Fomvasss\Billing\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Two passes: TrialWillEnd for trials about to run out (the "prompt for a card" hook — once per
 * subscription, trial_ends_notified_at is the marker), then the expiry itself. No event on expiry
 * on purpose — the consumer just reads `status` to decide what to block (see the itschats trial
 * case in "Бізнес-модель itschats" in the package plan).
 */
class ExpireTrialsCommand extends Command
{
    protected $signature = 'billing:expire-trials';

    protected $description = 'Dispatch TrialWillEnd for trials about to run out, mark expired trials as ended';

    public function handle(): int
    {
        $this->dispatchTrialEndingNotices();

        $count = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->update(['status' => SubscriptionStatus::Ended]);

        $this->info("Expired {$count} trial subscription(s).");

        return self::SUCCESS;
    }

    protected function dispatchTrialEndingNotices(): void
    {
        $noticeDays = (int) config('billing.trial_ending_notice_days', 3);

        Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNull('trial_ends_notified_at')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())
            ->where('trial_ends_at', '<=', now()->addDays($noticeDays))
            ->chunkById(200, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $subscription->update(['trial_ends_notified_at' => now()]);

                    TrialWillEnd::dispatch($subscription);
                }
            });
    }
}
