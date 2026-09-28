<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Contracts\InvoiceRenderer;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Tests\Fixtures\RequireStaffHeader;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/** The signed PDF link is a choice per app: on (default), guarded by the app's own middleware, or off. */
class InvoiceLinksTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.invoices.enabled', true);
        $app['config']->set('billing.invoices.seller', ['name' => 'Seller']);
    }

    protected function routeOff($app): void
    {
        $app['config']->set('billing.invoices.pdf_route', false);
    }

    protected function routeGuarded($app): void
    {
        $app['config']->set('billing.invoices.pdf_middleware', [RequireStaffHeader::class]);
    }

    #[DefineEnvironment('routeOff')]
    public function test_the_signed_route_can_be_turned_off(): void
    {
        $this->assertFalse(Route::has('billing.invoices.pdf'));

        $this->expectException(BillingException::class);
        Billing::invoicePdfUrl(Billing::issueInvoice($this->payment()));
    }

    #[DefineEnvironment('routeGuarded')]
    public function test_the_apps_own_middleware_guards_the_signed_link_too(): void
    {
        $this->fakeRenderer();
        $url = Billing::invoicePdfUrl(Billing::issueInvoice($this->payment()));

        $this->get($url)->assertUnauthorized();
        $this->get($url, ['X-Staff' => 'yes'])->assertOk();
    }

    public function test_by_default_the_signed_link_alone_opens_the_document(): void
    {
        $this->fakeRenderer();

        $this->get(Billing::invoicePdfUrl(Billing::issueInvoice($this->payment())))->assertOk();
    }

    private function fakeRenderer(): void
    {
        $this->app->instance(InvoiceRenderer::class, new class implements InvoiceRenderer {
            public function pdf(string $html): string
            {
                return '%PDF-fake';
            }
        });
    }

    private function payment(): Payment
    {
        $user = TestUser::create(['name' => 'Buyer']);

        return Payment::create(['status' => 'pending', 'type' => 'charge', 'gateway' => 'monobank', 'amount' => 1000, 'currency' => 'UAH',
            'payable_type' => TestUser::class, 'payable_id' => $user->id, 'billable_type' => TestUser::class, 'billable_id' => $user->id]);
    }
}
