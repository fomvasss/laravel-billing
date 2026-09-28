<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Enums\PaymentInitiation;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Events\SubscriptionCancelled;
use Fomvasss\Billing\Events\SubscriptionCreated;
use Fomvasss\Billing\Events\SubscriptionRenewed;
use Fomvasss\Billing\Events\TrialWillEnd;
use Fomvasss\Billing\Exceptions\NotSupportedException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Plan;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Billing, provider-managed. Shapes follow API 2026-08-26.dahlia (docs.stripe.com):
 * period on subscription items, invoice → subscription through parent.subscription_details,
 * the invoice's PaymentIntent only inside its expanded payments list.
 */
class StripeSubscriptionTest extends TestCase
{
    private Price $price;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.stripe.secret_key', 'sk_test_123');
        $app['config']->set('billing.gateways.stripe.webhook_secret', 'whsec_test');
        $app['config']->set('billing.return_urls.success', 'https://example.test/thanks');
        $app['config']->set('billing.return_urls.failed', 'https://example.test/sorry');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $this->price = Price::create([
            'plan_id' => $plan->id,
            'currency' => 'USD',
            'amount' => 2900,
            'pricing_type' => 'flat',
            'interval' => 'month',
            'interval_count' => 1,
        ]);
    }

    public function test_starting_a_subscription_opens_a_subscription_checkout_with_an_inline_price(): void
    {
        [$subscription, $payment] = $this->checkout();

        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);

        $result = Billing::startSubscription($payment);

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_1', $result->url);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/sessions')
            && $request['mode'] === 'subscription'
            && $request['customer'] === 'cus_1'
            && $request['line_items'][0]['price_data']['recurring']['interval'] === 'month'
            && (int) $request['line_items'][0]['price_data']['recurring']['interval_count'] === 1
            && (int) $request['line_items'][0]['price_data']['unit_amount'] === 2900
            && $request['line_items'][0]['price_data']['product_data']['name'] === 'Pro'
            && $request['subscription_data']['metadata'] === ['payment_id' => (string) $payment->id, 'subscription_id' => (string) $subscription->id]);
    }

    public function test_a_trial_checkout_records_the_zero_and_asks_stripe_for_the_trial(): void
    {
        $this->price->update(['trial_days' => 14]);
        [, $payment] = $this->checkout(['amount' => 0]);

        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);

        Billing::startSubscription($payment);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/sessions')
            && (int) $request['subscription_data']['trial_period_days'] === 14
            && (int) $request['line_items'][0]['price_data']['unit_amount'] === 2900);
    }

    public function test_the_completed_checkout_links_the_subscription_and_the_first_invoice_gives_the_paid_period(): void
    {
        Event::fake([SubscriptionCreated::class, SubscriptionRenewed::class, PaymentSucceeded::class]);
        [$subscription, $payment] = $this->checkout(['external_id' => 'cs_1']);
        $periodEnd = now()->addMonth()->startOfSecond();

        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_1' => Http::response($this->entity($subscription, $periodEnd)),
            'https://api.stripe.com/v1/invoices/in_1*' => Http::response(['payments' => ['data' => [['status' => 'paid', 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_1']]]]]),
        ]);

        $this->event('checkout.session.completed', [
            'id' => 'cs_1', 'mode' => 'subscription', 'payment_status' => 'paid', 'amount_total' => 2900, 'currency' => 'usd',
            'subscription' => 'sub_1', 'metadata' => ['payment_id' => (string) $payment->id], 'client_reference_id' => (string) $payment->id,
        ]);

        $fresh = $subscription->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame('sub_1', $fresh->external_id);
        $this->assertNull($fresh->current_period_ends_at, 'the session is not the proof of a paid period — the invoice is');
        Event::assertDispatchedTimes(SubscriptionCreated::class, 1);

        $this->event('invoice.paid', $this->invoice($payment, $subscription, 'in_1', 'subscription_create', $periodEnd));

        $this->assertTrue($subscription->fresh()->current_period_ends_at->equalTo($periodEnd));
        $this->assertSame('pi_1', $payment->fresh()->external_id, 'the first payment takes the invoice\'s PaymentIntent — refunds go through it');
        Event::assertDispatched(SubscriptionRenewed::class, fn (SubscriptionRenewed $e) => $e->previousStatus === SubscriptionStatus::Active);
        $this->assertSame(1, Payment::query()->count(), 'the first invoice is the checkout payment, not a new one');
    }

    public function test_a_renewal_invoice_is_a_new_payment_and_moves_the_paid_period(): void
    {
        Event::fake([PaymentSucceeded::class, SubscriptionRenewed::class]);
        [$subscription, $payment] = $this->checkout(['external_id' => 'pi_1', 'status' => 'paid']);
        $subscription->update(['external_id' => 'sub_1', 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->startOfSecond()]);
        $next = now()->addMonth()->startOfSecond();

        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_1' => Http::response($this->entity($subscription, $next)),
            'https://api.stripe.com/v1/invoices/in_2*' => Http::response(['payments' => ['data' => [['status' => 'paid', 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_2']]]]]),
        ]);

        $this->event('invoice.paid', $this->invoice($payment, $subscription, 'in_2', 'subscription_cycle', $next));

        $renewal = Payment::query()->where('external_id', 'pi_2')->first();
        $this->assertNotNull($renewal);
        $this->assertTrue($renewal->payable->is($subscription));
        $this->assertSame(2900, $renewal->amount);
        $this->assertSame('USD', $renewal->currency);
        $this->assertSame(PaymentInitiation::Automatic, $renewal->initiation);
        $this->assertTrue($subscription->fresh()->current_period_ends_at->equalTo($next));
        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payment->is($renewal));
        Event::assertDispatched(SubscriptionRenewed::class, fn (SubscriptionRenewed $e) => $e->previousStatus === SubscriptionStatus::Active);
    }

    public function test_a_refund_of_a_renewal_from_the_dashboard_finds_the_payment_by_its_payment_intent(): void
    {
        [$subscription] = $this->checkout();
        $renewal = Payment::create(['status' => 'paid', 'type' => 'charge', 'gateway' => 'stripe', 'amount' => 2900, 'currency' => 'USD', 'external_id' => 'pi_2',
            'payable_type' => Subscription::class, 'payable_id' => $subscription->id, 'billable_type' => $subscription->billable_type, 'billable_id' => $subscription->billable_id]);

        // The charge of a subscription invoice carries no metadata of ours.
        $this->event('charge.refunded', ['id' => 'ch_2', 'payment_intent' => 'pi_2', 'amount_refunded' => 900, 'metadata' => []]);

        $this->assertSame(900, $renewal->refundedAmount());
    }

    public function test_subscription_events_mirror_the_re_fetched_state(): void
    {
        Event::fake([SubscriptionCancelled::class]);
        $subscription = $this->linked();

        Http::fake(['https://api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()
            // flexible billing mode: a cancellation sets cancel_at only
            ->push([...$this->entity($subscription, now()->addMonth()), 'cancel_at' => now()->addDays(10)->timestamp, 'cancel_at_period_end' => false])
            ->push([...$this->entity($subscription, now()->addMonth()), 'status' => 'canceled', 'ended_at' => now()->timestamp]),
        ]);

        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'metadata' => []]);
        $this->assertTrue($subscription->fresh()->cancels_at->isFuture());
        $this->assertTrue($subscription->fresh()->isActive());

        $this->event('customer.subscription.deleted', ['id' => 'sub_1', 'metadata' => []]);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);
    }

    public function test_stripes_own_trial_reminder_becomes_trial_will_end_and_the_package_sends_none_of_its_own(): void
    {
        Event::fake([TrialWillEnd::class]);
        config(['billing.trial_ending_notices' => ['3 days']]);
        $subscription = $this->linked(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDay()]);

        $this->artisan('billing:expire-trials')->assertSuccessful();
        Event::assertNotDispatched(TrialWillEnd::class);

        $this->event('customer.subscription.trial_will_end', ['id' => 'sub_1', 'metadata' => []]);
        Event::assertDispatched(TrialWillEnd::class, fn (TrialWillEnd $e) => $e->subscription->is($subscription));
    }

    public function test_cancel_is_sent_to_stripe(): void
    {
        $subscription = $this->linked();
        $end = now()->addMonth()->startOfSecond();

        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()
                ->push([...$this->entity($subscription, $end), 'cancel_at' => $end->timestamp])       // POST cancel_at_period_end
                ->push([...$this->entity($subscription, $end), 'status' => 'canceled', 'ended_at' => now()->timestamp]), // DELETE
        ]);

        $subscription->cancel();
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['cancel_at_period_end'] === 'true');
        $this->assertTrue($subscription->fresh()->cancels_at->equalTo($end));

        $subscription->fresh()->cancel(atPeriodEnd: false);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
    }

    public function test_a_plan_swap_replaces_the_item_on_its_product(): void
    {
        $subscription = $this->linked();
        $newPrice = Price::create(['plan_id' => $this->price->plan_id, 'currency' => 'USD', 'amount' => 4900, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);

        Http::fake([
            'https://api.stripe.com/v1/products/prod_1' => Http::response(['id' => 'prod_1', 'active' => true, 'name' => 'Pro']),
            'https://api.stripe.com/v1/subscriptions/sub_1' => Http::response($this->entity($subscription, now()->addMonth())),
        ]);

        $subscription->swapPlan($newPrice);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/subscriptions/sub_1')
            && $request['items'][0]['id'] === 'si_1'
            && $request['items'][0]['price_data']['product'] === 'prod_1'
            && (int) $request['items'][0]['price_data']['unit_amount'] === 4900
            && $request['proration_behavior'] === 'create_prorations'
            && $request->hasHeader('Idempotency-Key'));
        $this->assertSame($newPrice->id, $subscription->fresh()->price_id);
    }

    public function test_the_inactive_product_checkout_made_is_replaced_by_an_active_one_for_the_swap(): void
    {
        $subscription = $this->linked();
        $newPrice = Price::create(['plan_id' => $this->price->plan_id, 'currency' => 'USD', 'amount' => 4900, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);

        // Live-found: Checkout's product_data product is inactive, can't be activated (Stripe
        // allows only metadata edits on it), and takes no new prices.
        Http::fake([
            'https://api.stripe.com/v1/products/prod_1' => Http::response(['id' => 'prod_1', 'active' => false, 'name' => 'Pro']),
            'https://api.stripe.com/v1/products' => Http::response(['id' => 'prod_2', 'active' => true]),
            'https://api.stripe.com/v1/subscriptions/sub_1' => Http::response($this->entity($subscription, now()->addMonth())),
        ]);

        $subscription->swapPlan($newPrice);

        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1/products') && $request['name'] === 'Pro');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/subscriptions/sub_1') && $request->method() === 'POST' && $request['items'][0]['price_data']['product'] === 'prod_2');
    }

    public function test_a_change_stripe_refuses_surfaces_its_reason(): void
    {
        $subscription = $this->linked();

        Http::fake(['https://api.stripe.com/v1/subscriptions/sub_1' => Http::response(['error' => ['code' => 'resource_missing', 'message' => 'No such subscription']], 400)]);

        $this->expectException(\Fomvasss\Billing\Exceptions\BillingException::class);
        $this->expectExceptionMessage('resource_missing');
        $subscription->cancel();
    }

    public function test_pause_is_refused_rather_than_faked_with_pause_collection(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->linked()->pause();
    }

    /** @return array{0: Subscription, 1: Payment} */
    private function checkout(array $paymentAttributes = []): array
    {
        $user = TestUser::create(['name' => 'Buyer']);
        $subscription = Subscription::create(['status' => SubscriptionStatus::Incomplete, 'gateway' => 'stripe', 'price_id' => $this->price->id,
            'billable_type' => TestUser::class, 'billable_id' => $user->id]);
        $payment = Payment::create(['status' => 'pending', 'type' => 'charge', 'gateway' => 'stripe', 'amount' => 2900, 'currency' => 'USD',
            'payable_type' => Subscription::class, 'payable_id' => $subscription->id, 'billable_type' => TestUser::class, 'billable_id' => $user->id, ...$paymentAttributes]);

        return [$subscription, $payment];
    }

    private function linked(array $attributes = []): Subscription
    {
        [$subscription] = $this->checkout();
        $subscription->update(['external_id' => 'sub_1', 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addDays(10)->startOfSecond(), ...$attributes]);

        return $subscription->fresh();
    }

    private function entity(Subscription $subscription, \DateTimeInterface $periodEnd): array
    {
        return [
            'id' => 'sub_1',
            'status' => 'active',
            'currency' => 'usd',
            'metadata' => ['subscription_id' => (string) $subscription->id],
            'cancel_at' => null,
            'cancel_at_period_end' => false,
            'ended_at' => null,
            'trial_end' => null,
            'items' => ['data' => [['id' => 'si_1', 'current_period_end' => $periodEnd->getTimestamp(), 'price' => ['id' => 'price_1', 'product' => 'prod_1']]]],
        ];
    }

    private function invoice(Payment $payment, Subscription $subscription, string $id, string $reason, \DateTimeInterface $periodEnd): array
    {
        return [
            'id' => $id,
            'billing_reason' => $reason,
            'amount_paid' => 2900,
            'currency' => 'usd',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => [
                'subscription' => 'sub_1',
                'metadata' => ['payment_id' => (string) $payment->id, 'subscription_id' => (string) $subscription->id],
            ]],
            'lines' => ['data' => [['period' => ['start' => now()->timestamp, 'end' => $periodEnd->getTimestamp()]]]],
        ];
    }

    private function event(string $type, array $object): void
    {
        static $n = 0;
        $body = json_encode(['id' => 'evt_' . ++$n, 'type' => $type, 'api_version' => '2026-08-26.dahlia', 'data' => ['object' => $object]]);
        $timestamp = time();

        $this->call('POST', route('billing.webhook', ['gateway' => 'stripe']), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=" . hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test')], $body)
            ->assertOk();
    }
}
