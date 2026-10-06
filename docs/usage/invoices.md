# Invoices and receipts

Optional documents for your customers: an **invoice** (a bill to pay, before the money arrives — B2B, bank transfer, an emailed pay link) and a **receipt** (proof of payment). They are not fiscal receipts — those come from the gateway's [fiscal basket](fiscal-receipts.md).

## Setup

1. Publish and run the `billing-migrations-invoices` group.
2. `BILLING_INVOICES_ENABLED=true` — without it the settle-on-payment listener and the PDF/preview routes are not registered.
3. Fill the seller: `BILLING_SELLER_*` or `billing.invoices.seller`.
4. `composer require barryvdh/laravel-dompdf` for PDFs, or bind your own `InvoiceRenderer`.

## Issuing

```php
use Fomvasss\Billing\DTO\BillingDetails;
use Fomvasss\Billing\Facades\Billing;

$invoice = Billing::issueInvoice($payment);                    // INV-2026-000001
$invoice = Billing::issueInvoice($payment, new BillingDetails(name: 'ТОВ «Ромашка»', taxId: '41234567'), [
    'note' => 'Згідно договору № 17 від 01.09.2026',           // printed under the totals
]);

$receipt = Billing::issueReceipt($payment);                    // RCP-2026-000001, paid charges only

Billing::renderInvoice($invoice);   // HTML in the document's locale
Billing::invoicePdf($invoice);      // PDF bytes
Billing::invoicePdfUrl($invoice);   // temporary signed link to the PDF
Billing::invoiceDocument($invoice); // the formatted snapshot (InvoiceDocument)
Billing::voidInvoice($invoice);     // keeps its number
```

Signatures:

```php
issueInvoice(Payment $payment, ?BillingDetails $buyer = null, array $extra = [], ?DateTimeInterface $dueAt = null, ?string $locale = null, ?BillingDetails $seller = null): Invoice
issueReceipt(Payment $payment, ?BillingDetails $buyer = null, array $extra = [], ?string $locale = null, ?BillingDetails $seller = null): Invoice
```

- **Idempotent** — one invoice and one receipt per payment (unique `type, payment_id`); asking again returns the existing document, ignoring the new arguments.
- `issueInvoice()` on a refund throws. On a pending payment the invoice is `issued`, due in `due_days`; on a paid one it comes out `paid`, without due date or pay link.
- `issueReceipt()` throws unless the payment is a paid charge. When the payment has an invoice, the receipt settles it: same seller, buyer, items, locale and `extra`, and `invoice_id` points back at it.
- `voidInvoice()` throws on a paid invoice (refund the payment instead). Numbers are never reused.
- Each issued document fires `InvoiceIssued` — split on `$invoice->isReceipt()`.

## Automatic documents

On `PaymentSucceeded` (when `invoices.enabled`):

- an `issued` invoice of the payment becomes `paid` and `InvoicePaid` fires;
- `auto_invoice` (`BILLING_INVOICES_AUTO_INVOICE`) — a payment paid without an invoice (a checkout, a renewal) gets one, issued already paid and naming the period just paid for;
- `auto_receipt` (`BILLING_INVOICES_AUTO_RECEIPT`) — the receipt is issued.

Nothing is issued automatically while the seller's name is empty — a document is a snapshot and would keep the empty seller forever. A payment recorded as paid by hand fires no event: call `issueReceipt()` yourself.

## A document is a snapshot

Seller, buyer, items and total are copied at issue time. A later change to the customer, the config or the subscription never rewrites an issued document; rendering reads only the snapshot.

### Seller and buyer

**Seller**: the `$seller` argument → the gateway block's own `seller` (`billing.gateways.paddle.seller`, read through the credential resolver — a merchant account of another legal entity) → `billing.invoices.seller`. Several sellers from a database: bind `InvoiceSellerContract`.

**Buyer**: the `$buyer` argument → the billable's `billingDetails()` when it implements `HasBillingDetails` → an empty name.

```php
use Fomvasss\Billing\Contracts\HasBillingDetails;
use Fomvasss\Billing\DTO\BillingDetails;

class Organization extends Model implements Billable, HasBillingDetails
{
    public function billingDetails(): BillingDetails
    {
        return new BillingDetails(name: $this->legal_name, taxId: $this->edrpou, address: $this->address, email: $this->billing_email);
    }
}
```

`BillingDetails` fields: `name`, `taxId`, `address`, `email`, `phone`, `iban`, `bank`, `logo`, `vatId`, `brand`.

### Items

From `InvoiceItemsContract` — by default the payable's `receiptItems()` (`HasReceiptItems`). Bind your own to take lines from where your fiscal basket comes from. The items must add up to the payment amount (else `BillingException`); none — one line for the whole amount, named "Subscription: {plan}" for a subscription payment, otherwise "Payment {number}" or a generic label.

### Subscription payments

A subscription document records the plan name in `extra['subscription']`, and — on an invoice where it is known — the paid period, printed under every line. The period is left out when it can't be known for sure: a receipt without an invoice, an invoice asked for after the payment was paid, a provider-managed subscription. A receipt settling an invoice keeps the invoice's. Pass your own `extra['subscription']` to override.

## Numbers

Each document has its own number, not `payments.number`: an unbroken sequence per series (`INV`, `RCP`), seller (tenant) and year, allocated under a row lock in `billing_document_sequences`. A document losing a race (the listener and a manual call) rolls its number back — no gap.

Format: `billing.invoices.number_format` — `{Y}` year, `{000000}` the counter padded to that width, `{N}` unpadded.

`number_per_tenant` (default `true`) keeps a sequence per tenant — right when each tenant is a seller. When you are the one seller and tenants are your customers, set it `false`.

## PDF and storage

PDF is generated on the fly by `InvoiceRenderer` (default `DompdfInvoiceRenderer`: A4, font subsetting, DejaVu Sans for Cyrillic; ~30 KB a page). Without `barryvdh/laravel-dompdf` it throws `BillingException`. Bind your own renderer for Chromium (`spatie/laravel-pdf`), Gotenberg, mPDF:

```php
$this->app->bind(\Fomvasss\Billing\Contracts\InvoiceRenderer::class, MyChromiumRenderer::class);
```

`BILLING_INVOICES_DISK` keeps a copy at `{storage.path}/{id}-{status}.pdf` — keyed by status, so a paid invoice doesn't serve its unpaid copy.

## Who can open a document

An invoice carries the buyer's details, so the package never serves one by a guessable URL:

- `invoicePdfUrl($invoice, ?int $ttlMinutes)` — a temporary signed link to `billing/invoices/{invoice}/pdf` (`link_ttl_minutes`, a week by default). No login needed — whoever the email is forwarded to opens it until it expires, and only rotating `APP_KEY` revokes sent links. Add `billing.invoices.pdf_middleware` (e.g. `['web', 'auth']`) to require a session as well.
- A customer cabinet — serve the PDF from your own route and turn the package route off (`pdf_route` false; `invoicePdfUrl()` then throws):

```php
Route::get('/cabinet/invoices/{invoice}.pdf', function (Invoice $invoice) {
    abort_unless($invoice->billable->is(auth()->user()), 403);

    return response(Billing::invoicePdf($invoice), 200, ['Content-Type' => 'application/pdf']);
})->middleware('auth');
```

- `billing/invoices/{invoice}/preview` (`billing.invoices.preview`) — HTML, `local`/`testing` only, for editing templates.
- The pay link printed on an unpaid invoice (`billing.pay`) is public by design — it opens a checkout, not the document.

## Customizing the template

From the simplest:

1. **Config** — seller, logo, footer, number format, locale, date format. Give the logo as a local file (absolute or relative to `public/`, ≤ 1 MB): it is embedded, so the preview and the PDF both show it. A URL shows in the preview and in Chromium, not in dompdf.
2. **One part** — the view is split into `header`, `parties`, `party`, `summary`, `items`, `totals`, `note`, `pay`, `payment`, `footer`. Copy one file to `resources/views/vendor/billing/invoices/partials/`; the rest keeps coming from the package (`--tag=billing-invoice-views` copies all).
3. **Template per document** — bind `InvoiceTemplateResolver` (by tenant, brand, language, type), or set a view name in the invoice's `template` column (`$invoice->update(['template' => 'invoices.acme'])`) — it wins over the resolver's default.
4. **Extra data** — bind `InvoiceViewDataContract`; its array is merged into the view.
5. **Translations** — `lang/vendor/billing/{uk,en,pl,de}/invoice.php` (`--tag=billing-lang`). Another language: add a file with the same keys and issue with `locale: 'cs'`. A missing key falls back to the app's `fallback_locale`. `pl` is worded for Poland (a *Faktura pro forma* — a VAT invoice there goes through KSeF), `de` for Germany.

Templates get `$document` (the snapshot, formatted — print legal content from it) and `$invoice` (the model, with `payment`, `invoice`, `receipts`, `billable`). Default templates use tables and plain CSS, which dompdf renders.

| Variable | What it is |
|---|---|
| `$document->type` | `invoice` / `receipt` |
| `$document->number`, `->status` | `INV-2026-000001`; `issued` / `paid` / `void` |
| `$document->seller`, `->buyer` | `BillingDetails`; the seller's local logo already embedded as `data:` |
| `$document->items` | `[['name', 'qty', 'unitPrice', 'total', 'sku', 'period'], ...]` — prices formatted, `period` like `28.09.2026 – 28.10.2026` or null |
| `$document->total`, `->currency` | `125,50 ₴`; `UAH` |
| `$document->issuedAt`, `->dueAt`, `->paidAt` | Dates formatted in the document's language |
| `$document->payUrl` | The permanent pay link — only on an `issued` invoice with a payment |
| `$document->invoiceNumber` | A receipt's invoice number |
| `$document->footer`, `->locale` | `extra['footer']` or config; the language |
| `$document->plan`, `->periodStartsAt`, `->periodEndsAt` | Subscription payments |
| `$document->paymentMethod` | A receipt's "how it was paid": the gateway's label at issue time, or "Bank transfer" for a manual payment |
| `$document->extra` | Everything passed in `$extra` |

## Emailing documents

Attach the PDFs rather than only linking them — the email outlives a signed link, and accounting keeps the file. Build the body from `Billing::invoiceDocument()`, the same snapshot the PDF prints:

```php
class BillingDocumentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice)
    {
        $this->afterCommit(); // an auto receipt is issued inside the webhook transaction
    }

    public function envelope(): Envelope
    {
        $document = Billing::invoiceDocument($this->invoice);

        return new Envelope(subject: __("billing::invoice.{$document->type}")." {$document->number} — {$document->seller->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.billing-document', with: ['document' => Billing::invoiceDocument($this->invoice)]);
    }

    public function attachments(): array
    {
        // a receipt goes out with the invoice it settles
        return collect([$this->invoice->invoice, $this->invoice])
            ->filter()
            ->map(fn (Invoice $doc) => Attachment::fromData(fn () => Billing::invoicePdf($doc), "{$doc->number}.pdf")->withMime('application/pdf'))
            ->all();
    }
}
```

```php
Event::listen(function (InvoiceIssued $event) {
    $invoice = $event->invoice;
    $to = $invoice->buyerDetails()->email ?? $invoice->billable?->email;

    if ($to !== null) {
        Mail::to($to)->locale($invoice->locale)->send(new BillingDocumentMail($invoice));
    }
});
```

`Attachment::fromData()` takes a closure, so the PDF is rendered by the worker at send time. Resending often — turn on `invoices.storage.disk`.
