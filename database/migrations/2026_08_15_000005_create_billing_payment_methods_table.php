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
            $table->id();
            $table->string('gateway', 50);
            // Free-text, not an enum — greespi's Radom (crypto) driver has no card brand/last4.
            $table->string('type', 30)->default('card');
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->string('tenant_id', 100)->nullable();
            $table->morphs('billable');
            $table->string('external_customer_id')->nullable();
            $table->string('external_id');

            $table->unique(['gateway', 'external_customer_id', 'external_id'], 'billing_payment_methods_unique_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_methods');
    }
};
