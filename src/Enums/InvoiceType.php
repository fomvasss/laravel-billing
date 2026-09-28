<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Enums;

enum InvoiceType: string
{
    /** A bill to pay — issued before the money arrives. */
    case Invoice = 'invoice';
    /** Proof of payment — issued once the money is in. */
    case Receipt = 'receipt';

    /** The config key of this type's number format and its default series. */
    public function series(): string
    {
        return match ($this) {
            self::Invoice => 'INV',
            self::Receipt => 'RCP',
        };
    }
}
