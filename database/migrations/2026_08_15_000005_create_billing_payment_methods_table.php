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
            // Free-text, not an enum — non-card methods (crypto wallets, ...) have no brand/last4.
            $table->string('type', 30)->default('card');
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            // dateTime, NOT timestamp — MySQL TIMESTAMP caps at 2038, card expiries already exceed it.
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->string('tenant_id', 100)->nullable();
            // String morph id — billables with int and UUID keys both fit (same as billing_payments).
            $table->string('billable_type');
            $table->string('billable_id', 64);
            // Not nullable: NULLs don't collide in a unique index, so a nullable column would let
            // the token uniqueness below be bypassed. Drivers without a gateway-side customer
            // object derive a stable id of their own (see MonobankGateway::walletId()).
            // 191, not the default 255: both columns sit in the unique index below, which has to
            // stay under InnoDB's 3072-byte key limit on utf8mb4. Gateway tokens are far shorter
            // than that (a Stripe pm_/cus_ id, a Monobank card token: tens of characters).
            $table->string('external_customer_id', 191);
            $table->string('external_id', 191);

            $table->index(['billable_type', 'billable_id']);
            // Scoped to the billable, NOT globally unique per token: the token identifies a CARD,
            // and one physical card may legitimately be saved by two different billables (a person
            // paying for their own account and for a company's, an owner of two organizations).
            // Keyed on the token alone, the second checkout would match the first one's row and
            // overwrite its billable — silently moving the card off the first billable, whose next
            // renewal then finds no card and goes into dunning for a card the customer still has.
            $table->unique(
                ['gateway', 'billable_type', 'billable_id', 'external_customer_id', 'external_id'],
                'billing_payment_methods_unique_token',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_methods');
    }
};
