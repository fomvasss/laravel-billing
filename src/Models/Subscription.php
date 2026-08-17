<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\SubscriptionAccessSuspended;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Events\SubscriptionPaused;
use Fomvasss\Billing\Events\SubscriptionPaymentFailed;
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
     * The dunning step: bumps recurring_attempts, either schedules the next retry inside a grace
     * window or — once max_recurring_attempts is reached — cancels outright. Shared by an actual
     * declined charge (HandleSubscriptionPaymentOutcome::handlePaymentFailed()) and a renewal that
     * never got as far as calling the gateway because there's no saved card to charge
     * (ProcessRecurringChargesCommand) — from the customer's perspective "no card on file" and "the
     * card was declined" deserve the identical grace/retry treatment, not a silent no-op for the
     * former. Callers own the trial/gateway-less guards that decide whether this is even reachable.
     */
    public function recordRenewalFailure(): void
    {
        $attempts = $this->recurring_attempts + 1;
        $maxAttempts = (int) config('billing.max_recurring_attempts', 3);
        // Captured before the update: access is only ever "cut" on the transition INTO past_due,
        // never on a later retry within the same episode (it's already off by then).
        $wasPastDue = $this->status === SubscriptionStatus::PastDue;

        if ($attempts >= $maxAttempts) {
            $this->update(['status' => SubscriptionStatus::Canceled, 'recurring_attempts' => $attempts, 'cancels_at' => now()]);

            SubscriptionCancelled::dispatch($this);

            return;
        }

        $this->update([
            'status' => SubscriptionStatus::PastDue,
            'recurring_attempts' => $attempts,
            'grace_ends_at' => now()->addDays((int) config('billing.grace_period_days', 3)),
            // Spaces the retries out — without this, the scheduler would re-pick a past_due
            // subscription every run and burn through max_recurring_attempts within minutes, making
            // the multi-day grace window meaningless.
            'next_retry_at' => now()->addHours((int) config('billing.retry_interval_hours', 24)),
        ]);

        SubscriptionPaymentFailed::dispatch($this);

        if (! $wasPastDue && ! $this->hasGraceAccess()) {
            SubscriptionAccessSuspended::dispatch($this);
        }
    }

    /**
     * Whether isActive() stays true for a past_due subscription while dunning retries run
     * (grace_access=true, the default) or turns false the moment the first renewal fails
     * (grace_access=false) — config('billing.grace_access'), overridable per Price. Purely an
     * access policy: recurring_attempts/grace_ends_at and the retry cycle are unaffected either way.
     */
    public function hasGraceAccess(): bool
    {
        return $this->price?->grace_access ?? (bool) config('billing.grace_access', true);
    }

    /**
     * The gateway owns this subscription's lifecycle (it was created through
     * SubscriptionGatewayContract::createSubscription() and carries the provider's own reference
     * in external_id) — renewals, dunning and trial conversion happen on the provider's side and
     * reach this row only through webhooks. The package's own schedulers skip such rows entirely;
     * external_id null = package-managed, the default and the only mode built-in drivers produce.
     * Per-SUBSCRIPTION, not per-gateway, on purpose: Stripe supports both modes at once.
     */
    public function isProviderManaged(): bool
    {
        return $this->external_id !== null;
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
            SubscriptionStatus::PastDue => $this->hasGraceAccess() && $this->onGracePeriod(),
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
        // Mirrors hasGraceAccess(): a Price with grace_access=true always counts; grace_access=null
        // falls back to the global default, so it's only added to the sub-query when that default
        // is actually true (grace_access=false on a Price is never included either way).
        $graceAccessDefault = (bool) config('billing.grace_access', true);

        $query->where(function (Builder $query) use ($graceAccessDefault) {
            $query->whereIn('status', [SubscriptionStatus::Trialing, SubscriptionStatus::Active])
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', SubscriptionStatus::PastDue)
                    ->where('grace_ends_at', '>', now())
                    ->whereHas('price', function (Builder $priceQuery) use ($graceAccessDefault) {
                        $priceQuery->where('grace_access', true);

                        if ($graceAccessDefault) {
                            $priceQuery->orWhereNull('grace_access');
                        }
                    }));
        });
    }

    /** @param  Builder<self>  $query */
    public function scopeForBillable(Builder $query, Model $billable): void
    {
        $query->where('billable_type', $billable->getMorphClass())->where('billable_id', $billable->getKey());
    }
}
