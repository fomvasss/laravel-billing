<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The last number handed out per series, seller and year — see Support\DocumentNumber.
        Schema::create('billing_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('series', 20);
            // The tenant the numbers belong to; '' for a single-seller app (a null in a unique
            // index wouldn't make the row unique).
            $table->string('scope', 100)->default('');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last')->default(0);
            $table->timestamps();

            $table->unique(['series', 'scope', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_document_sequences');
    }
};
