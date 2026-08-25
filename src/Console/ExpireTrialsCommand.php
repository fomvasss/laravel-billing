<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\TrialWillEnd;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Support\Intervals;
use Illuminate\Console\Command;

/**
 * Two passes: TrialWillEnd for trials about to run out (the "prompt for a card" hook — once per
 * subscription per notice, trial_notices_sent is the marker), then the expiry itself. No event on expiry
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
            // Provider-managed trials convert (or lapse) on the gateway's side and report back via
            // webhooks — ending one locally would fight the provider's own transition.
            ->whereNull('external_id')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->update(['status' => SubscriptionStatus::Ended]);

        $this->info("Expired {$count} trial subscription(s).");

        return self::SUCCESS;
    }

    /**
     * A LIST of reminders ('7 days', '1 hour', int = minutes), each fired at most once per
     * subscription (trial_notices_sent tracks which). The price's own trial_ending_notices
     * overrides the global config('billing.trial_ending_notices') — null = global list, [] = no
     * reminders for that price — so a yearly plan (7/3/1 days) and an hourly rental (1h/15m) can
     * coexist. When several become due at the same run (a trial created mid-window, or the
     * scheduler was down), only the closest one fires and the rest are marked sent — no burst.
     */
    protected function dispatchTrialEndingNotices(): void
    {
        $default = (array) config('billing.trial_ending_notices', ['3 days']);

        Subscription::query()
            ->with('price')
            ->where('status', SubscriptionStatus::Trialing)
            // The provider sends its own trial-ending webhook (Stripe trial_will_end) which the
            // driver maps to TrialWillEnd — local notices on top would double every reminder.
            ->whereNull('external_id')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())
            ->chunkById(200, function ($subscriptions) use ($default) {
                foreach ($subscriptions as $subscription) {
                    $notices = collect($subscription->price?->trial_ending_notices ?? $default)
                        ->mapWithKeys(fn ($notice) => [(string) $notice => Intervals::parse($notice, 'trial notice interval')])
                        ->sortBy(fn (\Carbon\CarbonInterval $interval) => $interval->totalSeconds);

                    $sent = $subscription->trial_notices_sent ?? [];

                    $due = $notices
                        ->filter(fn (\Carbon\CarbonInterval $interval, string $label) => ! in_array($label, $sent, true)
                            && $subscription->trial_ends_at->lessThanOrEqualTo(now()->add($interval)));

                    if ($due->isEmpty()) {
                        continue;
                    }

                    $subscription->update(['trial_notices_sent' => [...$sent, ...$due->keys()]]);

                    // the closest (smallest interval) reminder is the one worth saying out loud
                    TrialWillEnd::dispatch($subscription, $due->keys()->first());
                }
            });
    }
}
