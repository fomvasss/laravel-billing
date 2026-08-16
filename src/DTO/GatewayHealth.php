<?php

declare(strict_types=1);

namespace Fomvasss\Billing\DTO;

/**
 * Result of a ChecksGatewayHealth probe: credentials valid + API reachable, right now. A snapshot
 * for a settings-UI "test connection" button or a monitoring cron — never a guarantee the next
 * charge succeeds.
 */
final readonly class GatewayHealth
{
    public function __construct(
        public bool $ok,
        /** Human-readable detail — merchant name when up, the gateway's error when down. */
        public ?string $message = null,
        public ?float $latencyMs = null,
    ) {}

    public static function up(?string $message = null, ?float $latencyMs = null): self
    {
        return new self(true, $message, $latencyMs);
    }

    public static function down(?string $message = null, ?float $latencyMs = null): self
    {
        return new self(false, $message, $latencyMs);
    }
}
