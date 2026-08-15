<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends spatie/laravel-webhook-client's webhook_calls with the two columns laravel-billing needs
 * for dedup — no separate webhook_events table (see "Webhook pipeline" in the package plan).
 *
 * spatie ships create_webhook_calls_table as a .stub, not an auto-loaded migration — publish it
 * first: `php artisan vendor:publish --tag="webhook-client-migrations"` then `php artisan migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webhook_calls')) {
            throw new RuntimeException(
                'Table "webhook_calls" does not exist yet. Publish and run spatie/laravel-webhook-client\'s '
                . 'own migration first: php artisan vendor:publish --tag="webhook-client-migrations" && php artisan migrate'
            );
        }

        if (Schema::hasColumn('webhook_calls', 'external_id')) {
            return;
        }

        Schema::table('webhook_calls', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('name');
            $table->unique(['name', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_calls', function (Blueprint $table) {
            $table->dropUnique(['name', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
