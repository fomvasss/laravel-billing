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

    public function test_process_recurring_charges_runs_every_minute(): void
    {
        // a renewal lands within a minute of the period end — safe at this frequency
        // (indexed query, idempotent command, withoutOverlapping)
        $this->assertSame('* * * * *', $this->expressionFor('billing:process-recurring-charges'));
    }

    public function test_housekeeping_commands_run_hourly(): void
    {
        // Hourly, not daily: none of them gates access (isActive() reads the row's own dates), but
        // an hours-scale notice only lands if the notice pass runs at least that often.
        $this->assertSame('0 * * * *', $this->expressionFor('billing:expire-trials'));
        $this->assertSame('0 * * * *', $this->expressionFor('billing:send-period-notices'));
        $this->assertSame('0 * * * *', $this->expressionFor('billing:expire-pauses'));
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
