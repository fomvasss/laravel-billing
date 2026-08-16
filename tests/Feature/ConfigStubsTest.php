<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Tests\TestCase;

/**
 * config/billing.php ships a stub for every built-in gateway (Laravel's own config/services.php
 * convention). Those stubs and each driver's static credentialFields() are two separate places
 * listing the same keys — this keeps them from drifting apart when a driver gains a credential.
 */
class ConfigStubsTest extends TestCase
{
    public function test_every_built_in_gateway_has_a_config_stub(): void
    {
        $stubbed = array_keys((require __DIR__ . '/../../config/billing.php')['gateways']);

        foreach (array_keys(Billing::gateways()) as $gateway) {
            if ($gateway === 'fake') {
                continue; // local/testing only, no credentials at all
            }

            $this->assertContains($gateway, $stubbed, "config/billing.php has no stub for the \"{$gateway}\" gateway.");
        }
    }

    public function test_gateways_listing_says_which_gateways_need_dashboard_webhook_setup(): void
    {
        $gateways = Billing::gateways();

        // Stripe is the only built-in that delivers webhooks solely to a Dashboard-registered
        // endpoint; the rest pass the callback URL in every charge request.
        $this->assertTrue($gateways['stripe']['webhook_requires_dashboard_setup']);

        foreach (['monobank', 'liqpay', 'wayforpay', 'hutko', 'fake'] as $gateway) {
            $this->assertFalse($gateways[$gateway]['webhook_requires_dashboard_setup'], $gateway);
        }
    }

    public function test_config_stubs_match_each_drivers_credential_fields(): void
    {
        $config = (require __DIR__ . '/../../config/billing.php')['gateways'];

        foreach (Billing::gateways() as $gateway => $definition) {
            if ($gateway === 'fake') {
                continue;
            }

            $declared = array_column($definition['credential_fields'], 'name');
            sort($declared);

            $stubbed = array_keys($config[$gateway]);
            sort($stubbed);

            $this->assertSame(
                $declared,
                $stubbed,
                "config/billing.php stub for \"{$gateway}\" doesn't match its credentialFields().",
            );
        }
    }
}
