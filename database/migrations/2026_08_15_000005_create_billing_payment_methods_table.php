<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gateway', 50);
            // Free-text, not an enum — greespi's Radom (crypto) driver has no card brand/last4.
            $table->string('type', 30)->default('card');
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            // dateTime, NOT timestamp — MySQL TIMESTAMP caps at 2038-01-19, and card expiries
            // already exceed it (Stripe's test card is 12/55; live-found on the first real attach).
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->string('tenant_id', 100)->nullable();
            // String morph id — billables with int and UUID keys both fit (same as billing_payments).
            $table->string('billable_type');
            $table->string('billable_id', 64);
            $table->string('external_customer_id')->nullable();
            $table->string('external_id');

            $table->index(['billable_type', 'billable_id']);
            $table->unique(['gateway', 'external_customer_id', 'external_id'], 'billing_payment_methods_unique_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_methods');
    }
};
