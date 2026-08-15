<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

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

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
