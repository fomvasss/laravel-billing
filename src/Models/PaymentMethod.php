<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Models;

use Fomvasss\Billing\Concerns\DerivesTenantId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentMethod extends Model
{
    use DerivesTenantId;
    use HasUuids;

    protected $table = 'billing_payment_methods';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_default' => 'boolean',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
