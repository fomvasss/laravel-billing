<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

use Fomvasss\Billing\Concerns\DerivesTenantId;
use Fomvasss\Billing\DTO\BillingDetails;
use Fomvasss\Billing\Enums\InvoiceStatus;
use Fomvasss\Billing\Enums\InvoiceType;
use Fomvasss\Billing\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An invoice (a bill to pay) or a receipt (proof it was paid). Everything a document shows is a
 * snapshot taken at issue time — seller, buyer, items, total — so it never follows later changes to
 * the live models. Issue through Billing::issueInvoice()/issueReceipt(), not create().
 */
class Invoice extends Model
{
    use DerivesTenantId;
    use HasUuids;

    protected $table = 'billing_invoices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'total' => 'integer',
            'seller' => 'array',
            'buyer' => 'array',
            'items' => 'array',
            'extra' => 'array',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The invoice a receipt settles. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invoice_id');
    }

    /** Receipts issued against this invoice. */
    public function receipts(): HasMany
    {
        return $this->hasMany(self::class, 'invoice_id');
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isInvoice(): bool
    {
        return $this->type === InvoiceType::Invoice;
    }

    public function isReceipt(): bool
    {
        return $this->type === InvoiceType::Receipt;
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
    }

    public function money(): Money
    {
        return new Money($this->total, $this->currency);
    }

    public function sellerDetails(): BillingDetails
    {
        return BillingDetails::fromArray($this->seller ?? []);
    }

    public function buyerDetails(): BillingDetails
    {
        return BillingDetails::fromArray($this->buyer ?? []);
    }

    /** @param  Builder<self>  $query */
    public function scopeForBillable(Builder $query, Model $billable): void
    {
        $query->where('billable_type', $billable->getMorphClass())->where('billable_id', $billable->getKey());
    }
}
