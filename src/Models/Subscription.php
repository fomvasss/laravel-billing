<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Events\SubscriptionPaused;
use Fomvasss\Billing\Events\SubscriptionResumed;
use Fomvasss\Billing\Events\UsageLimitReached;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;

class Subscription extends Model
{
    use HasUuids;

    protected $table = 'billing_subscriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'qty' => 'integer',
            'current_usage' => 'float',
            'trial_ends_at' => 'datetime',
            'trial_notices_sent' => 'array',
            'current_period_ends_at' => 'datetime',
            'cancels_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'next_retry_at' => 'datetime',
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

    /**
     * Local status change only — none of the 5 built-in drivers implement SubscriptionGatewayContract
     * (see "Кроки реалізації" п.5), so there's no native-subscription gateway to delegate to yet.
     * When one is added, this is the natural place to check `$this->price->plan` etc. and call it.
     */
    public function cancel(bool $atPeriodEnd = true): void
    {
        if ($atPeriodEnd && $this->current_period_ends_at !== null) {
            $this->update(['cancels_at' => $this->current_period_ends_at]);

            return;
        }

        $this->update(['status' => SubscriptionStatus::Canceled, 'cancels_at' => now()]);

        SubscriptionCancelled::dispatch($this);
    }

    /** Local price swap — no proration, no gateway delegation (see cancel() docblock). */
    public function swapPlan(Price $newPrice): void
    {
        $this->update(['price_id' => $newPrice->id]);
    }

    /**
     * "Is the customer entitled to the service right now" — the one check most consumer code
     * actually wants. True while trialing, active, or still inside the dunning grace window
     * (a failed renewal shouldn't cut access off mid-retry); false once canceled/ended/paused.
     */
    public function isActive(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Trialing, SubscriptionStatus::Active => true,
            SubscriptionStatus::PastDue => $this->onGracePeriod(),
            default => false,
        };
    }

    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            && ($this->trial_ends_at === null || $this->trial_ends_at->isFuture());
    }

    /** A failed renewal still being retried — access usually stays on until this window closes. */
    public function onGracePeriod(): bool
    {
        return $this->grace_ends_at !== null && $this->grace_ends_at->isFuture();
    }

    public function isCanceled(): bool
    {
        return $this->status === SubscriptionStatus::Canceled;
    }

    /** Cancelled at period end (cancel()) but still running until then. */
    public function isCancelling(): bool
    {
        return $this->cancels_at !== null && $this->cancels_at->isFuture() && ! $this->isCanceled();
    }

    /**
     * Same definition as isActive() — entitled to the service right now, INCLUDING past_due still
     * inside the dunning grace window.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->whereIn('status', [SubscriptionStatus::Trialing, SubscriptionStatus::Active])
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', SubscriptionStatus::PastDue)
                    ->where('grace_ends_at', '>', now()));
        });
    }

    /** @param  Builder<self>  $query */
    public function scopeForBillable(Builder $query, Model $billable): void
    {
        $query->where('billable_type', $billable->getMorphClass())->where('billable_id', $billable->getKey());
    }
}
