<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->uuid('id')->primary(); // UUID v7 (Model::HasUuids) — time-ordered, not v4: no insert-fragmentation downside. See "Доменні таблиці" in the package plan.
            $table->string('status', 20)->default('pending');
            $table->string('type', 20)->default('charge');
            $table->string('gateway', 50)->nullable();
            $table->string('method', 50)->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('currency_code', 3);
            $table->string('converted_from_currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->timestamp('exchange_rate_at')->nullable();
            $table->string('external_id')->nullable();
            $table->string('payment_url', 2048)->nullable();
            $table->timestamp('payment_url_expires_at')->nullable();
            $table->string('link_token', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('tenant_id', 100)->nullable();
            $table->morphs('payable');
            $table->morphs('billable');
            // No hard FK constraint (self-reference during Schema::create) — indexed only.
            $table->uuid('parent_payment_id')->nullable();

            $table->index(['tenant_id', 'status']);
            $table->unique('link_token');
            $table->index(['gateway', 'external_id']);
            $table->index('parent_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
    }
};
