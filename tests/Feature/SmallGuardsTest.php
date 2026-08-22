<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class SmallGuardsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
    }

    public function test_an_unknown_gateway_webhook_is_a_404_not_a_500(): void
    {
        $this->postJson('/billing/webhooks/nosuchgateway', ['x' => 1])->assertNotFound();
    }

    /** The table outlives the request and ends up in backups — a stored signature is a replayable secret. */
    public function test_credential_headers_are_not_persisted_with_the_webhook(): void
    {
        config(['billing.gateways.fake.enabled' => true]);

        $this->postJson(route('billing.webhook', ['gateway' => 'fake']), ['payment_id' => 'x'], [
            'Stripe-Signature' => 't=1,v1=deadbeef',
            'Authorization' => 'Bearer secret-token',
            'X-Custom' => 'kept',
        ])->assertOk();

        $headers = BillingWebhookCall::query()->sole()->headers;

        $this->assertSame(['[redacted]'], $headers['stripe-signature']);
        $this->assertSame(['[redacted]'], $headers['authorization']);
        $this->assertSame(['kept'], $headers['x-custom']);
    }

    public function test_usage_cannot_be_reported_as_a_negative_correction(): void
    {
        $subscription = $this->subscription();

        $this->expectException(\InvalidArgumentException::class);

        $subscription->reportUsage(-5);
    }

    /** Charging a card the bank will refuse anyway just burns a dunning attempt and a gateway log line. */
    public function test_an_expired_card_goes_straight_to_dunning_without_calling_the_gateway(): void
    {
        Event::fake([\Fomvasss\Billing\Events\SubscriptionPaymentFailed::class]);
        Http::fake();

        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->subDay(),
        ]);

        PaymentMethod::create([
            'gateway' => 'stripe',
            'external_customer_id' => 'cus_1',
            'external_id' => 'pm_1',
            'is_default' => true,
            'expires_at' => now()->subMonth(),
            'billable_type' => $subscription->billable_type,
            'billable_id' => $subscription->billable_id,
        ]);

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()->status);
        $this->assertSame(0, Payment::query()->count());
        Http::assertNothingSent();
        Event::assertDispatchedTimes(\Fomvasss\Billing\Events\SubscriptionPaymentFailed::class, 1);
    }

    private function subscription(array $attributes = []): Subscription
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $plan = Plan::create(['code' => 'pro-' . uniqid(), 'name' => 'Pro']);
        $price = Price::create([
            'plan_id' => $plan->id,
            'gateway' => 'stripe',
            'currency' => 'USD',
            'amount' => 2900,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        return Subscription::create([
            'status' => SubscriptionStatus::Active,
            'gateway' => 'stripe',
            'price_id' => $price->id,
            'billable_type' => TestUser::class,
            'billable_id' => $user->id,
            ...$attributes,
        ]);
    }
}
