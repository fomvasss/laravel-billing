<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Events;

use Fomvasss\Billing\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The customer's browser came back from the gateway's checkout page (the package's return route).
 * A UX/analytics signal ONLY: the browser may never come back at all (closed tab, mobile app
 * switch), and $data is whatever the gateway put in the redirect — unverified. Never fulfil an
 * order or change a Payment from this; the webhook pipeline (PaymentSucceeded/PaymentFailed) is
 * the only source of truth.
 */
class CheckoutReturned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        /** 'success' | 'failed' — which return URL the gateway sent the browser to, nothing more. */
        public readonly string $outcome,
        /** Raw, unverified request data the gateway attached to the return redirect/POST. */
        public readonly array $data = [],
    ) {}
}
