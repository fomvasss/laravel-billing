<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // invoice — a bill to pay; receipt — proof that it was paid.
            $table->string('type', 20);
            $table->string('status', 20)->default('issued');
            // The document's own number (INV-2026-000001) — not payments.number, which references
            // the payment operation. See Support\DocumentNumber.
            $table->string('number', 64);
            $table->string('series', 20);
            $table->string('currency', 3);
            $table->unsignedBigInteger('total');
            // Snapshot at issue time — the document never follows later changes to the seller's
            // or the customer's details. Rendering reads these, never the live models.
            $table->json('seller');
            $table->json('buyer');
            $table->json('items');
            $table->json('extra')->nullable();
            $table->string('locale', 10)->nullable();
            // A Blade view name overriding InvoiceTemplateResolver for this one document.
            $table->string('template')->nullable();
            $table->dateTime('issued_at');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->foreignUuid('payment_id')->nullable()->constrained('billing_payments')->nullOnDelete();
            // A receipt points at the invoice it settles, when there was one.
            $table->uuid('invoice_id')->nullable()->index();
            $table->timestamps();
            $table->string('tenant_id', 100)->nullable();
            $table->string('billable_type')->nullable();
            $table->string('billable_id', 64)->nullable();

            // Numbers run per seller (tenant) — two tenants may both have INV-2026-000001.
            $table->unique(['tenant_id', 'number']);
            // One document of each type per payment: the receipt listener and a manual
            // issueReceipt() racing each other end with one receipt, not two.
            $table->unique(['type', 'payment_id']);
            $table->index(['billable_type', 'billable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
    }
};
