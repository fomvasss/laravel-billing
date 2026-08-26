<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionPeriodEnding;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Support\Intervals;
use Illuminate\Console\Command;

/**
 * The paid-period counterpart of ExpireTrialsCommand's notice pass: nobody else warns the customer
 * that money is about to move, because no built-in driver is provider-managed — the package's own
 * scheduler is what renews. Off unless period_ending_notices says otherwise (globally or per Price),
 * so a project that doesn't want advance notices doesn't pay for the scan.
 */
class SendPeriodNoticesCommand extends Command
{
    protected $signature = 'billing:send-period-notices';

    protected $description = 'Dispatch SubscriptionPeriodEnding before a paid period runs out';

    public function handle(): int
    {
        $default = (array) config('billing.period_ending_notices', []);

        $count = 0;

        Subscription::query()
            ->with('price')
            // Active only: a trial has its own notices (TrialWillEnd), a past_due one is already
            // being dunned with its own events, and paused/canceled/ended rows owe nothing.
            ->where('status', SubscriptionStatus::Active)
            // Provider-managed subscriptions renew on the gateway's side, which sends its own
            // upcoming-invoice notice — a local one on top would double every reminder.
            ->whereNull('external_id')
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '>', now())
            ->chunkById(200, function ($subscriptions) use ($default, &$count) {
                foreach ($subscriptions as $subscription) {
                    $count += $this->notify($subscription, $default) ? 1 : 0;
                }
            });

        $this->info("Dispatched {$count} period-ending notice(s).");

        return self::SUCCESS;
    }

    /** @param  list<string|int>  $default */
    protected function notify(Subscription $subscription, array $default): bool
    {
        $configured = $subscription->price?->period_ending_notices ?? $default;

        if ($configured === []) {
            return false;
        }

        $notices = collect($configured)
            ->mapWithKeys(fn ($notice) => [(string) $notice => Intervals::parse($notice, 'period notice interval')])
            ->sortBy(fn (\Carbon\CarbonInterval $interval) => $interval->totalSeconds);

        $sent = $subscription->period_notices_sent ?? [];

        $due = $notices
            ->filter(fn (\Carbon\CarbonInterval $interval, string $label) => ! in_array($label, $sent, true)
                && $subscription->current_period_ends_at->lessThanOrEqualTo(now()->add($interval)));

        if ($due->isEmpty()) {
            return false;
        }

        $subscription->update(['period_notices_sent' => [...$sent, ...$due->keys()]]);

        // Same rule as the trial notices: several due at once (a short period, or the scheduler was
        // down) is one reminder — the closest — not a burst.
        SubscriptionPeriodEnding::dispatch(
            $subscription,
            $due->keys()->first(),
            $subscription->cancels_at === null,
        );

        return true;
    }
}
