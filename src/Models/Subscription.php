<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionPaused;
use Fomvasss\Billing\Events\SubscriptionResumed;
use Fomvasss\Billing\Events\UsageLimitReached;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;

class Subscription extends Model
{
    protected $table = 'billing_subscriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'qty' => 'integer',
            'current_usage' => 'float',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancels_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'recurring_attempts' => 'integer',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /**
     * Quota consumption for the current period, orthogonal to price.pricing_type (works on flat
     * prices too — see "Ліміти/квоти" in the package plan). $idempotencyKey guards against
     * double-counting a retried call (cache, not a durable ledger — see plan for why).
     */
    public function reportUsage(float $quantity, ?string $idempotencyKey = null): void
    {
        if ($idempotencyKey && ! Cache::add("billing:usage:{$this->id}:{$idempotencyKey}", true, now()->addDay())) {
            return;
        }

        $wasOverLimit = $this->remainingUsage() !== null && $this->remainingUsage() <= 0;

        $this->increment('current_usage', $quantity);

        $includedUnits = $this->price?->included_units;

        if ($includedUnits !== null && ! $wasOverLimit && $this->current_usage >= $includedUnits) {
            UsageLimitReached::dispatch($this->fresh());
        }
    }

    /** Null when the price has no quota (included_units is null) — pure pay-as-you-go. */
    public function remainingUsage(): ?float
    {
        $includedUnits = $this->price?->included_units;

        return $includedUnits === null ? null : $includedUnits - $this->current_usage;
    }

    /**
     * Local fact only — the gateway is never called (see greespi's paused status in the package
     * plan). A gateway-side pause (Stripe pause_collection) is a separate, not-yet-needed contract.
     */
    public function pause(): void
    {
        if ($this->status === SubscriptionStatus::Paused) {
            return;
        }

        $this->update(['status' => SubscriptionStatus::Paused]);

        SubscriptionPaused::dispatch($this);
    }

    public function resume(): void
    {
        if ($this->status !== SubscriptionStatus::Paused) {
            return;
        }

        $this->update(['status' => SubscriptionStatus::Active]);

        SubscriptionResumed::dispatch($this);
    }
}
