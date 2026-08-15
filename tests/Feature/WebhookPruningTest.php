<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

class WebhookPruningTest extends TestCase
{
    public function test_webhook_calls_older_than_the_retention_window_are_pruned(): void
    {
        $old = BillingWebhookCall::create(['name' => 'fake', 'url' => 'x', 'created_at' => now()->subDays(40)]);
        $fresh = BillingWebhookCall::create(['name' => 'fake', 'url' => 'x']);

        $this->artisan('model:prune', ['--model' => [BillingWebhookCall::class]])->assertSuccessful();

        $this->assertNull(BillingWebhookCall::find($old->id));
        $this->assertNotNull(BillingWebhookCall::find($fresh->id));
    }
}
