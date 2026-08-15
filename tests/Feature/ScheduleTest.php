<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

/**
 * reconcile-pending-payments runs every 15 min, not hourly like the other two — it's the fallback
 * for a payment stuck pending because a webhook was lost, and reconcile_after_minutes (default 60)
 * already delays how soon a stuck payment even qualifies; hourly on top of that meant up to ~2h
 * before a real "paid but webhook lost" payment got noticed. See "Крон" in the package plan.
 */
class ScheduleTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.schedule.enabled', true);
    }

    public function test_reconcile_pending_payments_runs_every_15_minutes(): void
    {
        $this->assertSame('*/15 * * * *', $this->expressionFor('billing:reconcile-pending-payments'));
    }

    public function test_process_recurring_charges_runs_hourly(): void
    {
        $this->assertSame('0 * * * *', $this->expressionFor('billing:process-recurring-charges'));
    }

    public function test_expire_trials_runs_daily(): void
    {
        $this->assertSame('0 0 * * *', $this->expressionFor('billing:expire-trials'));
    }

    public function test_webhook_calls_are_pruned_daily(): void
    {
        $this->assertSame('0 0 * * *', $this->expressionFor('model:prune'));
    }

    private function expressionFor(string $command): ?string
    {
        $schedule = $this->app->make(Schedule::class);

        $event = collect($schedule->events())->first(fn ($event) => str_contains($event->command, $command));

        return $event?->expression;
    }
}
