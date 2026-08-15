<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\PaymentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'billing_payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'type' => PaymentType::class,
            'amount' => 'integer',
            'exchange_rate' => 'float',
            'exchange_rate_at' => 'datetime',
            'payment_url_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'raw_response' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * Auto-manage paid_at on status change — status=Paid stamps it (once), anything else clears it.
     * Ported from dropshop's Payment::booted() `saving()` hook, moved from every driver/manual-flow
     * caller into the base model (see "Додатково звірено" #2 in the package plan).
     */
    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            if (empty($payment->link_token)) {
                $payment->link_token = Str::random(40);
            }
        });

        static::saving(function (self $payment) {
            if (! $payment->isDirty('status')) {
                return;
            }

            $payment->paid_at = $payment->status === PaymentStatus::Paid
                ? ($payment->paid_at ?? now())
                : null;
        });
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parentPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_payment_id');
    }

    /** Refunds/chargebacks against this charge — child rows with type=refund. */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'parent_payment_id');
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    public function isRefund(): bool
    {
        return $this->type === PaymentType::Refund;
    }

    /** Total refunded against this charge, in minor units — 0 when nothing was refunded. */
    public function refundedAmount(): int
    {
        return (int) $this->refunds()->where('status', PaymentStatus::Paid)->sum('amount');
    }

    /** The checkout link is still usable — no need to call charge() again (see BillingManager::charge()). */
    public function hasActivePaymentUrl(): bool
    {
        return $this->payment_url !== null
            && ($this->payment_url_expires_at === null || $this->payment_url_expires_at->isFuture());
    }

    /** @param  Builder<self>  $query */
    public function scopePaid(Builder $query): void
    {
        $query->where('status', PaymentStatus::Paid);
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', PaymentStatus::Pending);
    }

    /** @param  Builder<self>  $query */
    public function scopeForBillable(Builder $query, Model $billable): void
    {
        $query->where('billable_type', $billable->getMorphClass())->where('billable_id', $billable->getKey());
    }
}
