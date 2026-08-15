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
            $table->string('external_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('cancels_at')->nullable();
            // Dunning — only relevant when gateway=recurring, see the package plan.
            $table->timestamp('grace_ends_at')->nullable();
            $table->unsignedInteger('recurring_attempts')->default(0);
            $table->timestamps();
            $table->string('tenant_id', 100)->nullable();
            $table->morphs('billable');
            $table->foreignUuid('price_id')->constrained('billing_prices')->cascadeOnDelete();

            $table->index(['status', 'current_period_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscriptions');
    }
};
