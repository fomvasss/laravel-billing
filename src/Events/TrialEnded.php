<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The trial ran out without converting — dispatched by billing:expire-trials right after the row
 * moves to `ended`, the counterpart of TrialWillEnd's "it's about to". The consumer's hook for
 * the "your free period is over" message: the reminders before the deadline and the fact after it
 * are different letters, and until this event the second one had nothing to hang on.
 */
class TrialEnded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Subscription $subscription) {}
}
