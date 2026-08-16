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
            $table->uuid('id')->primary();
            $table->string('status', 20)->default('pending');
            $table->string('type', 20)->default('charge');
            // Human-facing reference ("PAY-2026-000123"); consumer-assigned — see "Payment numbers" in README.
            $table->string('number', 64)->nullable()->unique();
            $table->string('gateway', 50)->nullable();
            $table->unsignedBigInteger('amount');
            // Gateway's commission, minor units in the payment's currency; null = unknown, never a
            // guessed 0. See "Gateway fee and net amount" in README.
            $table->unsignedBigInteger('fee')->nullable();
            $table->string('currency', 3);
            $table->string('converted_from_currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->timestamp('exchange_rate_at')->nullable();
            $table->string('external_id')->nullable();
            $table->string('payment_url', 2048)->nullable();
            $table->timestamp('payment_url_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            // Gateway's refund-API response (refund rows only) — webhook payloads live in billing_webhook_calls.
            $table->json('raw_response')->nullable();
            // Opaque, consumer-controlled — the package never reads or writes it. See "Recipes" in README.
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            // Snapshot of the billable's tenantId() at creation — scoping/reports; credential resolution reads the billable live.
            $table->string('tenant_id', 100)->nullable();
            // String morph ids — the package's Subscription (UUID) and a consumer's Order (int) both fit.
            $table->string('payable_type');
            $table->string('payable_id', 64);
            $table->string('billable_type');
            $table->string('billable_id', 64);
            // No hard FK constraint (self-reference during Schema::create) — indexed only.
            $table->uuid('parent_payment_id')->nullable();

            $table->index(['tenant_id', 'status']);
            $table->index(['gateway', 'external_id']);
            $table->index(['payable_type', 'payable_id']);
            $table->index(['billable_type', 'billable_id']);
            $table->index('parent_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
    }
};
