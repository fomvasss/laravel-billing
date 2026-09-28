<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

/**
 * Marker for a gateway whose provider sends its own "trial ends soon" event (Stripe's
 * customer.subscription.trial_will_end), which the driver maps to TrialWillEnd. billing:expire-trials
 * then leaves that gateway's provider-managed trials alone — local reminders on top would double
 * every one. A provider without such an event (Paddle) gets the package's own reminders, computed
 * from the mirrored trial_ends_at.
 */
interface ReportsTrialEnding
{
}
