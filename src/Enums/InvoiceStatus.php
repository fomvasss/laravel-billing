<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Enums;

enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Paid = 'paid';
    /** Withdrawn — kept, numbered, never reused. */
    case Void = 'void';
}
