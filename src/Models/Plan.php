<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

use Fomvasss\Billing\Exceptions\BillingException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUuids;

    protected $table = 'billing_plans';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // the database cascades a plan's prices without Eloquent events — check them here
        static::deleting(function (self $plan) {
            $price = $plan->prices()->whereHas('subscriptions')->first();

            if ($price !== null) {
                throw BillingException::priceHasSubscriptions((string) $price->getKey());
            }
        });
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
