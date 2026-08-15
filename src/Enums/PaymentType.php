<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Enums;

enum PaymentType: string
{
    case Charge = 'charge';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Charge',
            self::Refund => 'Refund',
        };
    }
}
