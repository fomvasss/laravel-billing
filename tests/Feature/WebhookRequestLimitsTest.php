<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * webhook.rate_limit / webhook.max_payload_kb — on by default, refused before the body is
 * validated or stored in billing_webhook_calls.
 */
class WebhookRequestLimitsTest extends TestCase
{
    public function test_requests_over_the_rate_limit_get_429(): void
    {
        config(['billing.webhook.rate_limit' => 2]);

        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), ['payment_id' => 'x'])->assertOk();
        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), ['payment_id' => 'x'])->assertOk();
        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), ['payment_id' => 'x'])->assertStatus(429);

        $this->assertSame(2, BillingWebhookCall::count());
    }

    public function test_the_rate_limit_can_be_disabled(): void
    {
        config(['billing.webhook.rate_limit' => null]);

        foreach (range(1, 5) as $i) {
            $this->postJson(route('billing.webhook', ['gateway' => 'fake']), ['payment_id' => 'x'])->assertOk();
        }
    }

    public function test_a_body_over_the_size_limit_gets_413_and_is_not_stored(): void
    {
        config(['billing.webhook.max_payload_kb' => 1]);

        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), ['payment_id' => str_repeat('x', 2048)])
            ->assertStatus(413);

        $this->assertSame(0, BillingWebhookCall::count());
    }

    public function test_a_body_within_the_limit_passes(): void
    {
        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), ['payment_id' => str_repeat('x', 2048)])
            ->assertOk();
    }
}
