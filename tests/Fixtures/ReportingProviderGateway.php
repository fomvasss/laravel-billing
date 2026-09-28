<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Fixtures;

use Fomvasss\Billing\Contracts\ReportsTrialEnding;

/** A provider that announces the end of a trial itself (like Stripe's trial_will_end). */
class ReportingProviderGateway extends FakeProviderGateway implements ReportsTrialEnding
{
}
