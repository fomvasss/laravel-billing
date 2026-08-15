<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

/**
 * Marker interface for whatever is being paid for (Order, one-off invoice, Subscription cycle).
 * No required methods — the morphTo relation on Payment works with any Eloquent model regardless;
 * this exists purely for type-hinting and instanceof checks (see HasReceiptItems).
 */
interface Payable {}
