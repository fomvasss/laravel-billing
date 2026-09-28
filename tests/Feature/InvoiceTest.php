<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Feature;

use Fomvasss\Billing\Contracts\InvoiceRenderer;
use Fomvasss\Billing\DTO\BillingDetails;
use Fomvasss\Billing\Enums\InvoiceStatus;
use Fomvasss\Billing\Enums\InvoiceType;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Events\InvoiceIssued;
use Fomvasss\Billing\Events\InvoicePaid;
use Fomvasss\Billing\Events\PaymentSucceeded;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Models\Invoice;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Support\DocumentNumber;
use Fomvasss\Billing\Tests\Fixtures\TestBillingUser;
use Fomvasss\Billing\Tests\Fixtures\TestItemizedOrder;
use Fomvasss\Billing\Tests\Fixtures\TestUser;
use Fomvasss\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

class InvoiceTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('billing.invoices.enabled', true);
        $app['config']->set('billing.invoices.seller', ['name' => 'ФОП Тестовий', 'tax_id' => '0000000000', 'iban' => 'UA000000000000000000000000000']);
        $app['config']->set('billing.invoices.locale', 'uk');
    }

    public function test_an_invoice_freezes_seller_buyer_and_items_at_issue_time(): void
    {
        Event::fake([InvoiceIssued::class]);
        $payment = $this->payment(order: true);

        $invoice = Billing::issueInvoice($payment);

        $this->assertSame(InvoiceType::Invoice, $invoice->type);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('INV-' . now()->year . '-000001', $invoice->number);
        $this->assertSame('ФОП Тестовий', $invoice->seller['name']);
        $this->assertSame('Buyer', $invoice->buyer['name']);
        $this->assertSame('1234567890', $invoice->buyer['tax_id']);
        $this->assertSame(3500, $invoice->total);
        $this->assertSame([3000, 500], array_column($invoice->items, 'total'));
        $this->assertNotNull($invoice->due_at);
        Event::assertDispatched(InvoiceIssued::class);

        // Later changes to the live models never rewrite a document already issued.
        $payment->billable->update(['name' => 'Renamed']);
        config(['billing.invoices.seller.name' => 'Інший ФОП']);
        $fresh = $invoice->fresh();
        $this->assertSame('Buyer', $fresh->buyer['name']);
        $this->assertSame('ФОП Тестовий', $fresh->seller['name']);
    }

    public function test_a_buyer_passed_at_issue_time_wins_and_without_items_one_line_covers_the_amount(): void
    {
        $invoice = Billing::issueInvoice($this->payment(), new BillingDetails(name: 'ТОВ Клієнт', taxId: '12345678'));

        $this->assertSame('ТОВ Клієнт', $invoice->buyer['name']);
        $this->assertCount(1, $invoice->items);
        $this->assertSame(3500, $invoice->items[0]['total']);
        $this->assertSame('Оплата послуг', $invoice->items[0]['name'], 'never the payment uuid');

        $numbered = $this->payment();
        $numbered->update(['number' => 'PAY-77']);
        $this->assertSame('Оплата PAY-77', Billing::issueInvoice($numbered)->items[0]['name']);
    }

    public function test_a_gateway_with_its_own_seller_issues_under_it_and_others_fall_back_to_the_general_one(): void
    {
        config(['billing.gateways.paddle.seller' => ['name' => 'Brand Two LLC']]);

        $this->assertSame('Brand Two LLC', Billing::issueInvoice($this->payment(gateway: 'paddle'))->seller['name']);
        $this->assertSame('ФОП Тестовий', Billing::issueInvoice($this->payment(gateway: 'monobank'))->seller['name']);
    }

    public function test_a_seller_passed_for_one_document_wins_and_the_receipt_keeps_the_invoices_seller(): void
    {
        config(['billing.invoices.auto_receipt' => true]);
        $payment = $this->payment();

        $invoice = Billing::issueInvoice($payment, seller: new BillingDetails(name: 'ТОВ «Разовий продавець»'));
        $this->assertSame('ТОВ «Разовий продавець»', $invoice->seller['name']);

        // The seller's details change before the payment arrives — the receipt is still the same deal.
        config(['billing.invoices.seller.name' => 'Новий ФОП']);
        $payment->transitionTo(PaymentStatus::Paid);
        $receipt = Billing::issueReceipt($payment);

        $this->assertSame('ТОВ «Разовий продавець»', $receipt->seller['name']);
    }

    public function test_a_subscription_document_keeps_the_plan_and_the_paid_period_it_was_issued_for(): void
    {
        config(['billing.invoices.auto_receipt' => true]);
        $user = TestBillingUser::create(['name' => 'Buyer']);
        $plan = \Fomvasss\Billing\Models\Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = \Fomvasss\Billing\Models\Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 3500, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);
        $periodEnd = now()->addDays(3)->startOfDay();
        $subscription = \Fomvasss\Billing\Models\Subscription::create(['status' => 'active', 'price_id' => $price->id, 'current_period_ends_at' => $periodEnd,
            'billable_type' => TestBillingUser::class, 'billable_id' => $user->id]);
        $payment = Payment::create(['status' => 'pending', 'type' => 'charge', 'gateway' => 'monobank', 'amount' => 3500, 'currency' => 'UAH',
            'payable_type' => $subscription->getMorphClass(), 'payable_id' => $subscription->id, 'billable_type' => TestBillingUser::class, 'billable_id' => $user->id]);

        $invoice = Billing::issueInvoice($payment);

        $expected = $periodEnd->copy()->addMonthNoOverflow()->toDateString();
        $this->assertSame(['plan' => 'Pro', 'period_starts_at' => $periodEnd->toDateString(), 'period_ends_at' => $expected], $invoice->extra['subscription']);
        $this->assertSame('Підписка «Pro»', $invoice->items[0]['name']);
        $html = Billing::renderInvoice($invoice);
        // the paid period sits under the line it pays for
        $this->assertStringContainsString($periodEnd->format('d.m.Y') . ' – ' . $periodEnd->copy()->addMonthNoOverflow()->format('d.m.Y'), $html);

        // A later swap to another plan doesn't touch the document — nor the receipt that settles it.
        $plan->update(['name' => 'Renamed']);
        $payment->transitionTo(PaymentStatus::Paid);
        PaymentSucceeded::dispatch($payment);

        $this->assertSame('Pro', $invoice->fresh()->extra['subscription']['plan']);
        $receipt = Invoice::query()->where('type', InvoiceType::Receipt)->where('payment_id', $payment->id)->first();
        $this->assertSame(['plan' => 'Pro', 'period_starts_at' => $periodEnd->toDateString(), 'period_ends_at' => $expected], $receipt->extra['subscription']);
        $this->assertSame($invoice->items, $receipt->items, 'the period line comes with the items');
    }

    public function test_a_receipt_without_an_invoice_names_the_plan_but_leaves_the_period_out(): void
    {
        $user = TestBillingUser::create(['name' => 'Buyer']);
        $plan = \Fomvasss\Billing\Models\Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = \Fomvasss\Billing\Models\Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 3500, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);
        $subscription = \Fomvasss\Billing\Models\Subscription::create(['status' => 'active', 'price_id' => $price->id, 'current_period_ends_at' => now()->addMonth(),
            'billable_type' => TestBillingUser::class, 'billable_id' => $user->id]);
        $payment = Payment::create(['status' => 'paid', 'type' => 'charge', 'gateway' => 'monobank', 'amount' => 3500, 'currency' => 'UAH',
            'payable_type' => $subscription->getMorphClass(), 'payable_id' => $subscription->id, 'billable_type' => TestBillingUser::class, 'billable_id' => $user->id]);

        $this->assertSame(['plan' => 'Pro'], Billing::issueReceipt($payment)->extra['subscription']);
    }

    public function test_the_summary_line_payment_block_vat_id_and_multiline_address(): void
    {
        config(['billing.invoices.seller.vat_id' => '123456789012', 'billing.invoices.seller.address' => "вул. Садова, 1\nЛуцьк 43000\nУкраїна"]);
        $payment = $this->payment();
        $invoice = Billing::issueInvoice($payment);

        $html = Billing::renderInvoice($invoice);
        $this->assertStringContainsString('до сплати до ' . $invoice->due_at->format('d.m.Y'), $html);
        $this->assertStringContainsString('ІПН платника ПДВ: 123456789012', $html);
        $this->assertStringContainsString('вул. Садова, 1<br />', $html);

        $payment->transitionTo(PaymentStatus::Paid);
        $receipt = Billing::issueReceipt($payment);
        $html = Billing::renderInvoice($receipt);

        $this->assertSame('Monobank Acquiring', $receipt->extra['payment_method']);
        $this->assertStringContainsString('оплачено ' . $receipt->paid_at->format('d.m.Y'), $html);
        $this->assertStringContainsString('Спосіб оплати', $html);
        $this->assertStringContainsString('Monobank Acquiring', $html);
    }

    public function test_an_email_gets_the_same_document_the_template_prints(): void
    {
        $payment = $this->payment();
        Billing::issueInvoice($payment);
        $payment->transitionTo(PaymentStatus::Paid);
        $receipt = Billing::issueReceipt($payment);

        $document = Billing::invoiceDocument($receipt);

        $this->assertSame($receipt->number, $document->number);
        $this->assertSame($receipt->invoice->number, $document->invoiceNumber);
        $this->assertSame('Monobank Acquiring', $document->paymentMethod);
        $this->assertStringContainsString($document->total, Billing::renderInvoice($receipt));
    }

    public function test_a_brand_is_printed_next_to_the_logo_and_kept_in_the_snapshot(): void
    {
        config(['billing.invoices.seller.logo' => 'https://example.com/logo.png', 'billing.invoices.seller.brand' => 'ITSpace']);
        $invoice = Billing::issueInvoice($this->payment());
        config(['billing.invoices.seller.brand' => 'Renamed']);

        $this->assertSame('ITSpace', $invoice->sellerDetails()->brand);
        $this->assertMatchesRegularExpression('#logo\.png"[^>]*></td>\s*<td class="brand"[^>]*>ITSpace</td>#', Billing::renderInvoice($invoice));
        $this->assertStringNotContainsString('class="brand"', Billing::renderInvoice(Billing::issueInvoice($this->payment(), seller: new BillingDetails('Other'))));
    }

    public function test_dates_follow_the_documents_language_unless_the_config_pins_one_format(): void
    {
        $this->travelTo('2026-09-28 12:00');
        $invoice = Billing::issueInvoice($this->payment());

        $this->assertSame('28.09.2026', Billing::invoiceDocument($invoice)->issuedAt);

        $invoice->update(['locale' => 'en']);
        $this->assertSame('Sep 28, 2026', Billing::invoiceDocument($invoice)->issuedAt);

        $invoice->update(['locale' => 'fr']);
        $this->assertStringEndsWith(' 28, 2026', Billing::invoiceDocument($invoice)->issuedAt, 'a language without its own file falls back to the fallback locale\'s format');

        config(['billing.invoices.date_format' => 'Y-m-d']);
        $this->assertSame('2026-09-28', Billing::invoiceDocument($invoice)->issuedAt);
    }

    public function test_every_shipped_language_has_every_label(): void
    {
        $keys = array_keys(require __DIR__ . '/../../lang/en/invoice.php');
        sort($keys);

        foreach (glob(__DIR__ . '/../../lang/*/invoice.php') as $file) {
            $theirs = array_keys(require $file);
            sort($theirs);
            $this->assertSame($keys, $theirs, $file);
        }
    }

    public function test_one_seller_selling_to_tenants_numbers_them_all_in_one_sequence(): void
    {
        $a = $this->payment();
        $b = $this->payment();
        $a->forceFill(['tenant_id' => 'org-a'])->save();
        $b->forceFill(['tenant_id' => 'org-b'])->save();

        $this->assertSame(Billing::issueInvoice($a)->number, Billing::issueInvoice($b)->number, 'a sequence per tenant by default');

        config(['billing.invoices.number_per_tenant' => false]);
        $c = $this->payment();
        $c->forceFill(['tenant_id' => 'org-c'])->save();
        $this->assertSame('INV-' . now()->year . '-000001', Billing::issueInvoice($c)->number, 'the global sequence is its own');
        $d = $this->payment();
        $d->forceFill(['tenant_id' => 'org-a'])->save();
        $this->assertSame('INV-' . now()->year . '-000002', Billing::issueInvoice($d)->number);
    }

    public function test_numbers_run_per_series_tenant_and_year(): void
    {
        $this->assertSame('INV-2026-000001', DocumentNumber::next('INV', null, 'INV-{Y}-{000000}', 2026));
        $this->assertSame('INV-2026-000002', DocumentNumber::next('INV', null, 'INV-{Y}-{000000}', 2026));
        $this->assertSame('RCP-2026-000001', DocumentNumber::next('RCP', null, 'RCP-{Y}-{000000}', 2026));
        $this->assertSame('INV-2026-000001', DocumentNumber::next('INV', 'tenant-b', 'INV-{Y}-{000000}', 2026));
        $this->assertSame('INV-2027-000001', DocumentNumber::next('INV', null, 'INV-{Y}-{000000}', 2027));
        $this->assertSame('2026/3', DocumentNumber::next('INV', null, '{Y}/{N}', 2026));
    }

    public function test_one_invoice_per_payment_and_a_paid_charge_gets_it_already_paid(): void
    {
        $payment = $this->payment();

        $this->assertTrue(Billing::issueInvoice($payment)->is(Billing::issueInvoice($payment)));
        $this->assertSame(1, Invoice::query()->count());

        $paid = $this->payment(status: 'paid');
        $invoice = Billing::issueInvoice($paid);

        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNull($invoice->due_at);
        $this->assertNotNull($invoice->paid_at);
        $this->assertNull(Billing::invoiceDocument($invoice)->payUrl, 'nothing left to pay');

        $this->expectException(BillingException::class);
        Billing::issueInvoice(Payment::create(['status' => 'paid', 'type' => 'refund', 'gateway' => 'monobank', 'amount' => 100, 'currency' => 'UAH',
            'parent_id' => $paid->id, 'payable_type' => $paid->payable_type, 'payable_id' => $paid->payable_id,
            'billable_type' => $paid->billable_type, 'billable_id' => $paid->billable_id]));
    }

    public function test_auto_invoice_pairs_a_payment_paid_without_an_invoice_with_one(): void
    {
        config(['billing.invoices.auto_receipt' => true, 'billing.invoices.auto_invoice' => true]);
        $payment = $this->payment(order: true);

        $payment->transitionTo(PaymentStatus::Paid);
        PaymentSucceeded::dispatch($payment);
        PaymentSucceeded::dispatch($payment);

        $invoice = Invoice::query()->where('type', InvoiceType::Invoice)->sole();
        $receipt = Invoice::query()->where('type', InvoiceType::Receipt)->sole();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame($invoice->id, $receipt->invoice_id);
        $this->assertSame($invoice->items, $receipt->items);
        $this->assertSame($invoice->number, Billing::invoiceDocument($receipt)->invoiceNumber);
    }

    public function test_an_auto_invoice_names_the_period_just_paid_for_not_the_next_one(): void
    {
        config(['billing.invoices.auto_receipt' => true, 'billing.invoices.auto_invoice' => true]);
        $user = TestBillingUser::create(['name' => 'Buyer']);
        $plan = \Fomvasss\Billing\Models\Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = \Fomvasss\Billing\Models\Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 3500, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);
        $periodEnd = now()->addHour()->startOfSecond();
        $subscription = \Fomvasss\Billing\Models\Subscription::create(['status' => 'active', 'price_id' => $price->id, 'current_period_ends_at' => $periodEnd,
            'billable_type' => TestBillingUser::class, 'billable_id' => $user->id]);
        $payment = Payment::create(['status' => 'pending', 'type' => 'charge', 'gateway' => 'monobank', 'amount' => 3500, 'currency' => 'UAH',
            'payable_type' => $subscription->getMorphClass(), 'payable_id' => $subscription->id, 'billable_type' => TestBillingUser::class, 'billable_id' => $user->id]);

        $payment->transitionTo(PaymentStatus::Paid);
        PaymentSucceeded::dispatch($payment);

        $this->assertTrue($subscription->fresh()->current_period_ends_at->gt($periodEnd), 'the renewal ran');
        $invoice = Invoice::query()->where('type', InvoiceType::Invoice)->sole();
        $this->assertSame($periodEnd->toDateString(), $invoice->extra['subscription']['period_starts_at']);
        $this->assertSame($periodEnd->copy()->addMonthNoOverflow()->toDateString(), $invoice->extra['subscription']['period_ends_at']);
    }

    public function test_an_invoice_asked_for_after_the_payment_leaves_the_period_out(): void
    {
        $user = TestBillingUser::create(['name' => 'Buyer']);
        $plan = \Fomvasss\Billing\Models\Plan::create(['code' => 'pro', 'name' => 'Pro']);
        $price = \Fomvasss\Billing\Models\Price::create(['plan_id' => $plan->id, 'currency' => 'UAH', 'amount' => 3500, 'pricing_type' => 'flat', 'interval' => 'month', 'interval_count' => 1]);
        $subscription = \Fomvasss\Billing\Models\Subscription::create(['status' => 'active', 'price_id' => $price->id, 'current_period_ends_at' => now()->addHour(),
            'billable_type' => TestBillingUser::class, 'billable_id' => $user->id]);
        $payment = Payment::create(['status' => 'pending', 'type' => 'charge', 'gateway' => 'monobank', 'amount' => 3500, 'currency' => 'UAH',
            'payable_type' => $subscription->getMorphClass(), 'payable_id' => $subscription->id, 'billable_type' => TestBillingUser::class, 'billable_id' => $user->id]);
        $payment->transitionTo(PaymentStatus::Paid);
        PaymentSucceeded::dispatch($payment);

        $invoice = Billing::issueInvoice($payment);

        $this->assertSame(['plan' => 'Pro'], $invoice->extra['subscription'], 'by now the renewal has moved the period on');
        $this->assertArrayNotHasKey('period', $invoice->items[0]);
    }

    public function test_the_app_can_supply_the_lines_and_a_subscription_period_lands_on_them(): void
    {
        $this->app->bind(\Fomvasss\Billing\Contracts\InvoiceItemsContract::class, fn () => new class implements \Fomvasss\Billing\Contracts\InvoiceItemsContract {
            public function items(Payment $payment): array
            {
                return [['name' => 'Безлім · місяць', 'qty' => 1, 'unitAmount' => $payment->amount, 'sku' => 'base-month']];
            }
        });

        $invoice = Billing::issueInvoice($this->payment(), extra: ['subscription' => ['plan' => 'Безлім', 'period_starts_at' => '2026-10-01', 'period_ends_at' => '2026-11-01']]);

        $this->assertSame('Безлім · місяць', $invoice->items[0]['name']);
        $this->assertSame('base-month', $invoice->items[0]['sku']);
        $this->assertSame(['starts_at' => '2026-10-01', 'ends_at' => '2026-11-01'], $invoice->items[0]['period']);

        $this->app->bind(\Fomvasss\Billing\Contracts\InvoiceItemsContract::class, fn () => new class implements \Fomvasss\Billing\Contracts\InvoiceItemsContract {
            public function items(Payment $payment): array
            {
                return [['name' => 'Wrong', 'qty' => 1, 'unitAmount' => 1]];
            }
        });
        $this->expectException(BillingException::class);
        Billing::issueInvoice($this->payment());
    }

    public function test_payment_settles_the_invoice_and_issues_the_receipt_once(): void
    {
        config(['billing.invoices.auto_receipt' => true]);
        Event::fake([InvoicePaid::class]);
        $payment = $this->payment(order: true);
        $invoice = Billing::issueInvoice($payment, extra: ['contract' => 'Д-17']);

        $payment->transitionTo(PaymentStatus::Paid);
        (new \Fomvasss\Billing\Listeners\SettleInvoiceOnPayment())->handle(new PaymentSucceeded($payment));
        (new \Fomvasss\Billing\Listeners\SettleInvoiceOnPayment())->handle(new PaymentSucceeded($payment));

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        Event::assertDispatchedTimes(InvoicePaid::class, 1);

        $receipts = Invoice::query()->where('type', InvoiceType::Receipt)->get();
        $this->assertCount(1, $receipts);
        $receipt = $receipts->first();
        $this->assertSame('RCP-' . now()->year . '-000001', $receipt->number);
        $this->assertSame($invoice->id, $receipt->invoice_id);
        $this->assertSame($invoice->items, $receipt->items);
        $this->assertSame('Д-17', $receipt->extra['contract']);
        $this->assertSame(InvoiceStatus::Paid, $receipt->status);
    }

    public function test_without_auto_receipt_the_invoice_is_settled_but_no_receipt_appears(): void
    {
        $payment = $this->payment();
        $invoice = Billing::issueInvoice($payment);

        $payment->transitionTo(PaymentStatus::Paid);
        PaymentSucceeded::dispatch($payment);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(0, Invoice::query()->where('type', InvoiceType::Receipt)->count());
    }

    public function test_a_receipt_for_a_payment_recorded_as_paid_by_hand(): void
    {
        $receipt = Billing::issueReceipt($this->payment(status: 'paid'));

        $this->assertTrue($receipt->isReceipt());
        $this->assertNull($receipt->invoice_id);
        $this->assertNotNull($receipt->paid_at);

        $this->expectException(BillingException::class);
        Billing::issueReceipt($this->payment());
    }

    public function test_void_keeps_the_number_and_a_paid_invoice_cannot_be_voided(): void
    {
        $invoice = Billing::issueInvoice($this->payment());

        Billing::voidInvoice($invoice);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
        $this->assertSame('INV-' . now()->year . '-000002', Billing::issueInvoice($this->payment())->number);

        $paid = Billing::issueInvoice($this->payment());
        $paid->update(['status' => InvoiceStatus::Paid]);
        $this->expectException(BillingException::class);
        Billing::voidInvoice($paid);
    }

    public function test_the_html_carries_the_snapshot_in_the_documents_locale(): void
    {
        $invoice = Billing::issueInvoice($this->payment(order: true));

        $html = Billing::renderInvoice($invoice);

        $this->assertStringContainsString('Рахунок', $html);
        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString('ФОП Тестовий', $html);
        $this->assertStringContainsString('Tracker', $html);
        $this->assertStringContainsString(route('billing.pay', $invoice->payment_id), $html);

        $invoice->update(['extra' => ['note' => 'Договір № 17 від 01.09.2026']]);
        $this->assertStringContainsString('Договір № 17 від 01.09.2026', Billing::renderInvoice($invoice->fresh()));

        app()->setLocale('de');
        $invoice->update(['locale' => 'en']);
        $this->assertStringContainsString('Invoice', Billing::renderInvoice($invoice->fresh()));
        $this->assertSame('de', app()->getLocale(), 'the app locale is restored after rendering');
    }

    public function test_a_local_logo_is_embedded_so_the_preview_and_the_pdf_both_show_it(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $file);
        config(['billing.invoices.seller.logo' => $file]);

        $html = Billing::renderInvoice(Billing::issueInvoice($this->payment()));

        $this->assertStringContainsString('src="data:image/png;base64,', $html);

        config(['billing.invoices.seller.logo' => 'https://cdn.example.test/logo.png']);
        $this->assertStringContainsString('src="https://cdn.example.test/logo.png"', Billing::renderInvoice(Billing::issueInvoice($this->payment())));

        config(['billing.invoices.seller.logo' => '/no/such/file.png']);
        $this->assertStringNotContainsString('<img', Billing::renderInvoice(Billing::issueInvoice($this->payment())), 'a missing file drops the logo instead of a broken image');
    }

    public function test_a_consumer_can_override_a_single_partial(): void
    {
        $dir = sys_get_temp_dir() . '/billing-views-' . uniqid();
        mkdir($dir . '/vendor/billing/invoices/partials', 0777, true);
        file_put_contents($dir . '/vendor/billing/invoices/partials/footer.blade.php', 'CUSTOM FOOTER');
        $this->app['view']->getFinder()->prependNamespace('billing', $dir . '/vendor/billing');

        $html = Billing::renderInvoice(Billing::issueInvoice($this->payment()));

        $this->assertStringContainsString('CUSTOM FOOTER', $html);
        $this->assertStringContainsString('ФОП Тестовий', $html, 'the rest still comes from the package');
    }

    public function test_the_pdf_goes_through_the_renderer_and_is_kept_per_status_when_storage_is_on(): void
    {
        Storage::fake('local');
        config(['billing.invoices.storage.disk' => 'local']);
        $renderer = new class implements InvoiceRenderer {
            public int $calls = 0;

            public function pdf(string $html): string
            {
                $this->calls++;

                return '%PDF-fake ' . strlen($html);
            }
        };
        $this->app->instance(InvoiceRenderer::class, $renderer);
        $invoice = Billing::issueInvoice($this->payment());

        Billing::invoicePdf($invoice);
        Billing::invoicePdf($invoice);
        $this->assertSame(1, $renderer->calls, 'the stored copy is served the second time');

        $invoice->update(['status' => InvoiceStatus::Paid]);
        Billing::invoicePdf($invoice->fresh());
        $this->assertSame(2, $renderer->calls, 'a paid invoice does not serve its unpaid copy');
    }

    public function test_the_default_renderer_produces_a_real_pdf_with_cyrillic(): void
    {
        $this->app->register(\Barryvdh\DomPDF\ServiceProvider::class);

        $pdf = Billing::invoicePdf(Billing::issueInvoice($this->payment(order: true)));

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_the_pdf_route_needs_a_valid_signature(): void
    {
        $this->app->instance(InvoiceRenderer::class, new class implements InvoiceRenderer {
            public function pdf(string $html): string
            {
                return '%PDF-fake';
            }
        });
        $invoice = Billing::issueInvoice($this->payment());

        $this->get(route('billing.invoices.pdf', $invoice))->assertForbidden();
        $this->get(Billing::invoicePdfUrl($invoice))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_preview_renders_html_in_testing(): void
    {
        $invoice = Billing::issueInvoice($this->payment());

        $this->get(route('billing.invoices.preview', $invoice))->assertOk()->assertSee($invoice->number);
    }

    private function payment(bool $order = false, string $gateway = 'monobank', string $status = 'pending'): Payment
    {
        $user = TestBillingUser::create(['name' => 'Buyer']);
        $payable = $order ? TestItemizedOrder::create(['title' => 'Order']) : $user;

        return Payment::create([
            'status' => $status,
            'type' => 'charge',
            'gateway' => $gateway,
            'amount' => 3500,
            'currency' => 'UAH',
            'payable_type' => $payable::class,
            'payable_id' => $payable->id,
            'billable_type' => TestBillingUser::class,
            'billable_id' => $user->id,
        ]);
    }
}
