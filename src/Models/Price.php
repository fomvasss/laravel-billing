<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

use Fomvasss\Billing\Enums\Interval;
use Fomvasss\Billing\Enums\PricingType;
use Fomvasss\Billing\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Price extends Model
{
    use HasUuids;

    protected $table = 'billing_prices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'interval' => Interval::class,
            'interval_count' => 'integer',
            'trial_days' => 'integer',
            'trial_ending_notices' => 'array',
            'period_ending_notices' => 'array',
            'retry_intervals' => 'array',
            'grace_access' => 'boolean',
            'pricing_type' => PricingType::class,
            'included_units' => 'float',
            'quota_interval' => Interval::class,
            'quota_interval_count' => 'integer',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * amount + currency as one value object — for showing the price on a pricing page or doing
     * arithmetic on it. The column stays a plain minor-unit integer (see Payment::money()).
     */
    public function money(): Money
    {
        return new Money($this->amount, $this->currency);
    }

    /**
     * Whether the quota renews on its own cycle instead of on the billing period — the "pay for a
     * year, get 10 000 units every month" shape. Both halves are required on purpose: a quota
     * interval without included_units has nothing to reset, so it is treated as absent rather than
     * as a broken configuration that would silently zero a counter the consumer owns.
     */
    public function hasOwnQuotaCycle(): bool
    {
        return $this->quota_interval !== null && $this->included_units !== null;
    }

    /**
     * How much of BillingManager::resolveChargeAmount()'s per-unit result a renewal charge
     * actually owes — flat ignores qty/usage entirely (1×), licensed multiplies by seats, metered
     * by what's been consumed this period. Kept as a ratio, not an absolute amount, so it composes
     * with currency resolution instead of duplicating it (resolveChargeAmount() already decides
     * the per-unit rate/currency off $this->amount — this only scales that result).
     */
    public function chargeMultiplier(Subscription $subscription): int|float
    {
        return match ($this->pricing_type) {
            PricingType::Flat => 1,
            PricingType::Licensed => $subscription->qty,
            PricingType::Metered => $subscription->current_usage,
        };
    }
}
