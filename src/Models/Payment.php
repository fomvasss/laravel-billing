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
use Illuminate\Support\Facades\Log;

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
            'fee' => 'integer',
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

    /**
     * Applies a status change with one invariant: once Paid, a webhook/reconcile-driven transition
     * can never move this row away from it — a delayed or out-of-order delivery (an earlier decline
     * arriving after a later success, or a re-issued reference's stale "expired" landing after the
     * new one already paid) must not revert an already-paid Payment. Paid -> Paid (a duplicate
     * success delivery) is allowed as a no-op. Reissuing a failed/canceled Payment back to Paid
     * (PaymentLinkController's "old link expired, the new one paid") is allowed — Paid is the only
     * terminal value here.
     *
     * $attributes are merged into the same update() call (external_id, fee, ...) so callers don't
     * need a second write. Returns false — no write happens — when the transition is rejected; the
     * caller should treat the webhook/poll result as Ignored rather than as this outcome.
     */
    public function transitionTo(PaymentStatus $status, array $attributes = []): bool
    {
        if ($this->status === PaymentStatus::Paid && $status !== PaymentStatus::Paid) {
            Log::warning('Billing: ignored a payment status transition away from Paid', [
                'payment_id' => $this->id,
                'from' => $this->status->value,
                'to' => $status->value,
            ]);

            return false;
        }

        $this->update([...$attributes, 'status' => $status]);

        return true;
    }

    /**
     * Total refunded against this charge, in minor units — 0 when nothing was refunded. Counts
     * soft-deleted refund rows too: the money left the merchant account whether or not the row was
     * later hidden, and dropping them would re-open room to refund the same amount twice.
     */
    public function refundedAmount(): int
    {
        return (int) $this->refunds()->withTrashed()->where('status', PaymentStatus::Paid)->sum('amount');
    }

    /**
     * Lookup by the human-facing payment number ("PAY-2026-000123") — the package stores the
     * column but never generates it; your app assigns numbers in a Payment::creating() hook
     * (numbering schemes are project-specific). See "Payment numbers" in the README.
     */
    public static function findByNumber(string $number): ?self
    {
        return static::query()->where('number', $number)->first();
    }

    /**
     * What the merchant actually receives: amount minus the gateway's fee, minor units. Null while
     * the fee is unknown (the gateway didn't report it and nothing else assigned one) — never a
     * guess. Derived, not stored: refundedAmount() reasoning. See "Gateway fee and net amount" in
     * the README.
     */
    public function netAmount(): ?int
    {
        return $this->fee === null ? null : $this->amount - $this->fee;
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
