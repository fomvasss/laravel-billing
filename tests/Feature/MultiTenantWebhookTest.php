<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Contracts\Billable;
use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Jobs\ProcessWebhookJob;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Support\WebhookTenant;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * A webhook has to pick a secret before it can verify anything, so a multi-merchant app needs the
 * tenant in the callback URL itself — put there at charge time and read back by every validator.
 */
class MultiTenantWebhookTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.gateways.wayforpay.merchant_account', 'default_merch');
        $app['config']->set('billing.gateways.wayforpay.merchant_domain', 'example.test');
        $app['config']->set('billing.gateways.wayforpay.secret_key', 'default_secret');
    }

    public function test_the_callback_url_carries_the_billables_tenant(): void
    {
        Http::fake(['*' => Http::response(['url' => 'https://secure.wayforpay.com/x'])]);

        Billing::charge($this->payment());

        Http::assertSent(fn ($request) => str_contains($request['serviceUrl'], WebhookTenant::QUERY_KEY . '=acme'));
    }

    public function test_a_webhook_is_verified_against_that_tenants_secret(): void
    {
        $this->app->bind(CredentialResolverContract::class, fn () => new class implements CredentialResolverContract {
            public function resolve(string $gateway, ?string $tenantId): array
            {
                return $tenantId === 'acme'
                    ? ['merchant_account' => 'acme_merch', 'secret_key' => 'acme_secret', 'merchant_domain' => 'acme.test']
                    : config("billing.gateways.{$gateway}", []);
            }
        });

        $payment = $this->payment();
        $payload = [
            'merchantAccount' => 'acme_merch',
            'orderReference' => (string) $payment->id,
            'amount' => 50.0,
            'currency' => 'UAH',
            'authCode' => '123',
            'cardPan' => '44**44',
            'transactionStatus' => 'Approved',
            'reasonCode' => 1100,
        ];
        $payload['merchantSignature'] = hash_hmac('md5', implode(';', [
            $payload['merchantAccount'], $payload['orderReference'], $payload['amount'], $payload['currency'],
            $payload['authCode'], $payload['cardPan'], $payload['transactionStatus'], $payload['reasonCode'],
        ]), 'acme_secret');

        $url = route('billing.webhook', ['gateway' => 'wayforpay', WebhookTenant::QUERY_KEY => 'acme']);

        $this->postJson($url, $payload)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status->value);
    }

    /** Without the hint the default secret is used, which can't verify this tenant's signature. */
    public function test_the_same_webhook_without_the_hint_is_rejected(): void
    {
        $this->app->bind(CredentialResolverContract::class, fn () => new class implements CredentialResolverContract {
            public function resolve(string $gateway, ?string $tenantId): array
            {
                return $tenantId === 'acme'
                    ? ['merchant_account' => 'acme_merch', 'secret_key' => 'acme_secret', 'merchant_domain' => 'acme.test']
                    : config("billing.gateways.{$gateway}", []);
            }
        });

        $payment = $this->payment();
        $payload = [
            'merchantAccount' => 'acme_merch',
            'orderReference' => (string) $payment->id,
            'amount' => 50.0,
            'currency' => 'UAH',
            'authCode' => '123',
            'cardPan' => '44**44',
            'transactionStatus' => 'Approved',
            'reasonCode' => 1100,
            'merchantSignature' => 'whatever',
        ];

        $this->postJson(route('billing.webhook', ['gateway' => 'wayforpay']), $payload)->assertForbidden();
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    /**
     * Verifying the signature is only half of it: the queued job builds the driver again, and
     * anything handleWebhook() asks the gateway for (Stripe's saveCard flow) must go out with the
     * same tenant's key — not the default merchant's.
     */
    public function test_the_queued_job_talks_to_the_gateway_as_the_hinted_tenant(): void
    {
        $this->app['config']->set('billing.gateways.stripe.secret_key', 'sk_default');
        $this->app->bind(CredentialResolverContract::class, fn () => new class implements CredentialResolverContract {
            public function resolve(string $gateway, ?string $tenantId): array
            {
                return $tenantId === 'acme' ? ['secret_key' => 'sk_acme'] : config("billing.gateways.{$gateway}", []);
            }
        });
        Http::fake([
            'https://api.stripe.com/v1/payment_intents/pi_77*' => Http::response(['payment_method' => [
                'id' => 'pm_acme', 'customer' => 'cus_1', 'card' => ['last4' => '4242', 'brand' => 'visa'],
            ]]),
            'https://api.stripe.com/v1/customers/cus_1' => Http::response(['id' => 'cus_1']),
        ]);

        $payment = $this->payment(['gateway' => 'stripe', 'currency' => 'USD']);

        $call = BillingWebhookCall::create([
            'name' => 'stripe',
            'url' => route('billing.webhook', ['gateway' => 'stripe', WebhookTenant::QUERY_KEY => 'acme']),
            'payload' => ['type' => 'checkout.session.completed', 'data' => ['object' => [
                'id' => 'cs_1',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_77',
                'customer' => 'cus_1',
                'amount_total' => 5000,
                'currency' => 'usd',
                'metadata' => ['payment_id' => (string) $payment->id],
            ]]],
        ]);

        (new ProcessWebhookJob($call))->handle();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->header('Authorization') === ['Bearer sk_acme']);
        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame('acme', PaymentMethod::where('external_id', 'pm_acme')->firstOrFail()->tenant_id);
    }

    private function payment(array $overrides = []): Payment
    {
        $user = TenantedUser::create(['name' => 'Buyer']);

        return Payment::create([...[
            'status' => 'pending',
            'type' => 'charge',
            'gateway' => 'wayforpay',
            'amount' => 5000,
            'currency' => 'UAH',
            'payable_type' => TenantedUser::class,
            'payable_id' => $user->id,
            'billable_type' => TenantedUser::class,
            'billable_id' => $user->id,
        ], ...$overrides]);
    }
}

class TenantedUser extends TestUser implements Billable
{
    protected $table = 'test_users';

    public function tenantId(): ?string
    {
        return 'acme';
    }
}
