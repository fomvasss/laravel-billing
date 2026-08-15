<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Own table, own migration — no longer extending spatie/laravel-webhook-client's webhook_calls
 * (dropped as a dependency, see "Webhook pipeline" in the package plan). unique(name, external_id)
 * is the whole dedup mechanism: ProcessWebhookJob claims it via an UPDATE on this row, a duplicate
 * delivery's claim hits the same constraint an INSERT would.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_webhook_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 50);
            $table->string('url', 2048);
            $table->string('external_id')->nullable();
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->json('exception')->nullable();
            $table->timestamps();

            $table->unique(['name', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_calls');
    }
};
