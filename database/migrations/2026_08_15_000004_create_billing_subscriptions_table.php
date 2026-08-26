<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 20)->default('trialing');
            $table->string('gateway', 50)->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('current_usage', 18, 4)->default(0);
            // Ownership marker, not just a reference: non-null = provider-managed, the package's
            // schedulers skip the row. See Subscription::isProviderManaged().
            $table->string('external_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            // trial_ending_notices entries already fired — each reminder at most once per subscription.
            $table->json('trial_notices_sent')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            // period_ending_notices entries already fired for the current period — cleared on renewal.
            $table->json('period_notices_sent')->nullable();
            $table->timestamp('cancels_at')->nullable();
            // Scheduled auto-resume for Subscription::pause($until) — null means an indefinite
            // pause, only resume() ends it. See billing:expire-pauses.
            $table->timestamp('pause_ends_at')->nullable();
            // Dunning state — see "Recurring charges" in README.
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedInteger('recurring_attempts')->default(0);
            $table->timestamps();
            $table->string('tenant_id', 100)->nullable();
            // String morph id — billables with int and UUID keys both fit (same as billing_payments).
            $table->string('billable_type');
            $table->string('billable_id', 64);
            $table->foreignUuid('price_id')->constrained('billing_prices')->cascadeOnDelete();

            $table->index(['billable_type', 'billable_id']);
            $table->index(['status', 'current_period_ends_at']);
            $table->index(['status', 'pause_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscriptions');
    }
};
