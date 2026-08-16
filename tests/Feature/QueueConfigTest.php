<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Jobs\ProcessWebhookJob;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

class QueueConfigTest extends TestCase
{
    public function test_the_webhook_job_uses_the_configured_connection_and_queue(): void
    {
        config()->set('billing.queue.connection', 'redis');
        config()->set('billing.queue.queue', 'billing');

        $job = new ProcessWebhookJob(new BillingWebhookCall(['name' => 'fake']));

        $this->assertSame('redis', $job->connection);
        $this->assertSame('billing', $job->queue);
    }

    public function test_the_webhook_job_falls_back_to_the_app_defaults(): void
    {
        $job = new ProcessWebhookJob(new BillingWebhookCall(['name' => 'fake']));

        $this->assertNull($job->connection);
        $this->assertNull($job->queue);
    }
}
