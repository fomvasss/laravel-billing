<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrialWillEnd
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        /**
         * Which reminder this is — the config('billing.trial_ending_notices') entry that fired
         * ('7 days', '15 minutes', ...), so a listener can pick the right message. Null when the
         * event comes from a native-subscription gateway's webhook instead of billing:expire-trials.
         */
        public readonly ?string $notice = null,
    ) {}
}
