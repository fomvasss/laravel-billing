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
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('amount');
            $table->string('interval', 20)->nullable();
            $table->unsignedInteger('interval_count')->default(1);
            $table->unsignedInteger('trial_days')->default(0);
            $table->string('external_price_id')->nullable();
            $table->string('unit_label')->nullable();
            $table->decimal('included_units', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreignUuid('plan_id')->constrained('billing_plans')->cascadeOnDelete();

            $table->index(['plan_id', 'gateway', 'currency_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_prices');
    }
};
