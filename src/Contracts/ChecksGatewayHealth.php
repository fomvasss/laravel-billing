<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Contracts;

use Fomvasss\Billing\DTO\GatewayHealth;

/**
 * Optional — a live, SIDE-EFFECT-FREE probe of the gateway: do the configured credentials work
 * and is the API reachable. Gateways with a clean introspection endpoint use it (Stripe /balance,
 * Monobank /merchant/details); the rest probe by requesting the status of a nonexistent payment
 * and telling "order not found" (credentials fine) apart from "invalid signature" (they aren't) —
 * the discriminating error codes are live-verified per driver, see each healthCheck().
 */
interface ChecksGatewayHealth
{
    public function healthCheck(): GatewayHealth;
}
