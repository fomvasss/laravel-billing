<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

use Fomvasss\Billing\Enums\Interval;
use Fomvasss\Billing\Enums\PricingType;
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
            'pricing_type' => PricingType::class,
            'included_units' => 'float',
            'is_active' => 'boolean',
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
