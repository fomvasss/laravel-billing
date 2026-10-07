<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Console;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Illuminate\Console\Command;

/**
 * `billing:health` — probe every configured health-capable gateway (or one, by name) and exit
 * non-zero when anything is down, so a monitoring cron / uptime system can consume it directly.
 * Read-only, side-effect-free (see ChecksGatewayHealth).
 */
class HealthCommand extends Command
{
    protected $signature = 'billing:health {gateway? : Probe a single gateway by name}';

    protected $description = 'Probe gateway credentials/availability and report per-gateway health';

    public function handle(BillingManager $billing): int
    {
        $gateways = $this->argument('gateway')
            ? [$this->argument('gateway')]
            : collect($billing->gateways())->filter(fn (array $g) => $g['capabilities']['health'])->keys()->all();

        $rows = [];
        $allUp = true;

        foreach ($gateways as $name) {
            // without an argument a registered gateway with none of its secrets set is one this app
            // doesn't use — reporting it DOWN would fail every monitoring run
            if (! $this->argument('gateway') && ! $this->isConfigured($billing, $name)) {
                $rows[] = [$name, 'skipped', '—', 'not configured'];

                continue;
            }

            $health = $billing->health($name); // unknown gateway / no capability → the exception is the answer

            $allUp = $allUp && $health->ok;

            $rows[] = [
                $name,
                $health->ok ? '<info>up</info>' : '<error>DOWN</error>',
                $health->latencyMs !== null ? $health->latencyMs . ' ms' : '—',
                (string) $health->message,
            ];
        }

        $this->table(['Gateway', 'Status', 'Latency', 'Detail'], $rows);

        return $allUp ? self::SUCCESS : self::FAILURE;
    }

    private function isConfigured(BillingManager $billing, string $name): bool
    {
        $secrets = collect($billing->gateway($name)['credential_fields'])->where('secret', true)->pluck('name');

        if ($secrets->isEmpty()) {
            return true;
        }

        $credentials = app(CredentialResolverContract::class)->resolve($name, null);

        return $secrets->contains(fn (string $field) => filled($credentials[$field] ?? null));
    }
}
