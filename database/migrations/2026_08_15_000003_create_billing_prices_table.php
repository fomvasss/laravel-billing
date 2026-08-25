<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pricing_type', 20)->default('flat');
            $table->string('gateway', 50)->nullable();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->string('interval', 20)->nullable();
            $table->unsignedInteger('interval_count')->default(1);
            $table->unsignedInteger('trial_days')->default(0);
            // Per-price override of config('billing.trial_ending_notices'): null = global list, [] = off.
            $table->json('trial_ending_notices')->nullable();
            // Per-price override of config('billing.retry_intervals'): null = global list, [] = no retries.
            $table->json('retry_intervals')->nullable();
            // Per-price override of config('billing.grace_access'): null = global default.
            $table->boolean('grace_access')->nullable();
            $table->string('external_price_id')->nullable();
            $table->string('unit_label')->nullable();
            $table->decimal('included_units', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            // Opaque, consumer-controlled — the package never reads or writes it (same as Plan.meta).
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->foreignUuid('plan_id')->constrained('billing_plans')->cascadeOnDelete();

            $table->index(['plan_id', 'gateway', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_prices');
    }
};
