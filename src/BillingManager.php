<?php

declare(strict_types=1);

namespace Fomvasss\Billing;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Contracts\CurrencyConverterContract;
use Fomvasss\Billing\Contracts\HasReceiptItems;
use Fomvasss\Billing\Contracts\ManagesProviderSubscriptions;
use Fomvasss\Billing\Contracts\PaymentGatewayContract;
use Fomvasss\Billing\Contracts\RefundsPayments;
use Fomvasss\Billing\Contracts\StartsProviderSubscriptions;
use Fomvasss\Billing\Contracts\SubscriptionGatewayContract;
use Fomvasss\Billing\Contracts\TokenizesPaymentMethod;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\ResolvedAmount;
use Fomvasss\Billing\Enums\PaymentInitiation;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\PaymentType;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Exceptions\NotSupportedException;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Support\DefaultWebhookResponder;
use Fomvasss\Billing\Support\Money;
use Fomvasss\Billing\Support\WebhookResultDispatcher;
use Fomvasss\Billing\Support\WebhookTenant;
use Fomvasss\Billing\Contracts\HasBillingDetails;
use Fomvasss\Billing\Contracts\InvoiceRenderer;
use Fomvasss\Billing\Contracts\InvoiceItemsContract;
use Fomvasss\Billing\Contracts\InvoiceSellerContract;
use Fomvasss\Billing\Contracts\InvoiceTemplateResolver;
use Fomvasss\Billing\Contracts\InvoiceViewDataContract;
use Fomvasss\Billing\DTO\BillingDetails;
use Fomvasss\Billing\DTO\InvoiceDocument;
use Fomvasss\Billing\Enums\InvoiceStatus;
use Fomvasss\Billing\Enums\InvoiceType;
use Fomvasss\Billing\Events\InvoiceIssued;
use Fomvasss\Billing\Models\Invoice;
use Fomvasss\Billing\Support\DocumentNumber;
use Fomvasss\Billing\Support\InvoiceDocumentFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class BillingManager
{
    /** @var array<string, class-string<PaymentGatewayContract>> */
    protected array $drivers = [];

    /** @var array<string, class-string<\Fomvasss\Billing\Contracts\SignatureValidator>> */
    protected array $signatureValidators = [];

    /** @var array<string, class-string<\Fomvasss\Billing\Contracts\WebhookResponder>> */
    protected array $responders = [];

    /**
     * @param  class-string<PaymentGatewayContract>  $class  FQCN, not a closure — lets BillingManager
     *   call static methods (label(), supportedCurrencies(), credentialFields()) via gateways()
     *   without ever instantiating the driver (no credentials needed just to list it).
     */
    public function extend(string $name, string $class): static
    {
        if ($name === 'fake' && ! app()->environment(['local', 'testing'])) {
            throw new BillingException('The "fake" billing gateway can only be registered in local/testing environments.');
        }

        $this->drivers[$name] = $class;

        return $this;
    }

    /**
     * One call alongside extend() registers everything the incoming webhook route needs for this
     * gateway — no separate config file, no per-gateway route (a single wildcard route handles all
     * of them, resolved through this registry at request time).
     *
     * @param  class-string<\Fomvasss\Billing\Contracts\SignatureValidator>  $signatureValidator
     * @param  class-string<\Fomvasss\Billing\Contracts\WebhookResponder>|null  $responder  Defaults to a bare 200 — pass your own when the gateway requires a specific acknowledgment body (WayForPay does).
     */
    public function registerWebhook(string $name, string $signatureValidator, ?string $responder = null): static
    {
        $this->signatureValidators[$name] = $signatureValidator;
        $this->responders[$name] = $responder ?? DefaultWebhookResponder::class;

        return $this;
    }

    public function signatureValidatorFor(string $name): string
    {
        return $this->signatureValidators[$name] ?? throw BillingException::unknownGateway($name);
    }

    public function responderFor(string $name): string
    {
        return $this->responders[$name] ?? DefaultWebhookResponder::class;
    }

    public function driver(?string $name, ?string $tenantId = null): PaymentGatewayContract
    {
        // a payment or subscription without a gateway (manual, or a trial not paid yet) — the
        // caller passed $payment->gateway straight through
        if ($name === null) {
            throw new BillingException('No gateway set — a manual payment or subscription has no driver to call.');
        }

        $class = $this->drivers[$name] ?? throw BillingException::unknownGateway($name);

        $credentials = app(CredentialResolverContract::class)->resolve($name, $tenantId);

        return app()->makeWith($class, [
            'credentials' => $credentials,
            'gatewayName' => $name,
        ]);
    }

    /**
     * The currencies a gateway accepts. The driver's static supportedCurrencies() is the default;
     * config('billing.gateways.{name}.currencies') overrides it — narrow it to what YOUR merchant
     * account actually has enabled, or extend it when the driver's hardcoded list lags behind the
     * gateway (no gateway exposes a "list my currencies" API, so the driver's list is always an
     * approximation).
     */
    public function supportedCurrencies(string $gateway): array
    {
        $class = $this->drivers[$gateway] ?? throw BillingException::unknownGateway($gateway);

        $override = config("billing.gateways.{$gateway}.currencies");

        return is_array($override) && $override !== []
            ? array_map(strtoupper(...), $override)
            : $class::supportedCurrencies();
    }

    /** @return array<string, array{key: string, label: string, currencies: array, credential_fields: array, webhook_url: string, webhook_requires_dashboard_setup: bool, capabilities: array}> */
    public function gateways(): array
    {
        return collect($this->drivers)->mapWithKeys(fn (string $class, string $name) => [$name => [
            'key' => $name,
            'label' => $class::label(),
            'currencies' => $this->supportedCurrencies($name),
            'credential_fields' => $class::credentialFields(),
            'webhook_url' => route('billing.webhook', ['gateway' => $name]),
            // true = paste webhook_url into the gateway's dashboard; false = the driver already
            // sends it in every charge request, nothing to configure manually
            'webhook_requires_dashboard_setup' => $class::requiresDashboardWebhook(),
            'capabilities' => [
                'refunds' => is_subclass_of($class, RefundsPayments::class),
                'subscriptions' => is_subclass_of($class, StartsProviderSubscriptions::class)
                    || is_subclass_of($class, ManagesProviderSubscriptions::class)
                    || is_subclass_of($class, SubscriptionGatewayContract::class),
                'tokenization' => is_subclass_of($class, TokenizesPaymentMethod::class),
                'health' => is_subclass_of($class, \Fomvasss\Billing\Contracts\ChecksGatewayHealth::class),
            ],
        ]])->all();
    }

    /**
     * Names of the registered gateways whose driver implements $contract — for the package's own
     * passes that treat gateways by capability without building a driver per row.
     *
     * @return list<string>
     */
    public function gatewaysImplementing(string $contract): array
    {
        return array_keys(array_filter($this->drivers, fn (string $class) => is_subclass_of($class, $contract)));
    }

    public function gateway(string $name): ?array
    {
        return $this->gateways()[$name] ?? null;
    }

    /**
     * The orchestration a bare $driver->charge() call can't do on its own: resolves the driver
     * for $payment->gateway (with $payment->billable's tenant, for dynamic per-tenant credentials),
     * calls it, then writes the result's external_id/payment_url/payment_url_expires_at back onto
     * $payment.
     *
     * payment_url is ALWAYS a plain redirectable link here, regardless of whether the driver
     * returned $result->url or $result->form — a form-only gateway (LiqPay, currently the only
     * one) gets its form cached and served by CheckoutFormController so callers never have to
     * branch on which one they got. Cached, not recomputed per visit: recomputing would assume
     * charge() is side-effect-free for every current AND future form-returning driver, which isn't
     * safe to bake in. The cache TTL and payment_url_expires_at are the same value on purpose —
     * hasActivePaymentUrl() must not say "alive" for a link whose cached form is already gone.
     *
     * $options->receiptItems auto-fills from $payment->payable->receiptItems() when the caller
     * didn't already set one explicitly and $payable implements HasReceiptItems — the fiscal
     * basket a driver like Monobank/LiqPay needs, without every caller repeating the same
     * "does this order implement HasReceiptItems" check themselves.
     */
    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        return $this->openCheckout($payment, $options, fn (PaymentGatewayContract $driver, ChargeOptions $options) => $driver->charge($payment, $options));
    }

    /**
     * charge()'s twin for the first payment of a provider-managed subscription: the same checkout
     * orchestration, but the driver asks the provider to start its own subscription (see
     * StartsProviderSubscriptions). $payment->payable must be the Subscription row, created
     * `incomplete` with its gateway set; it becomes provider-managed once the driver's webhook links
     * it. A package-managed subscription's first payment still goes through charge().
     */
    public function startSubscription(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        if (! $payment->payable instanceof Subscription) {
            throw new BillingException("Payment {$payment->id} is not for a subscription — startSubscription() needs the Subscription as its payable.");
        }

        return $this->openCheckout($payment, $options, function (PaymentGatewayContract $driver, ChargeOptions $options) use ($payment) {
            if (! $driver instanceof StartsProviderSubscriptions) {
                throw NotSupportedException::forCapability($payment->gateway, StartsProviderSubscriptions::class);
            }

            return $driver->startSubscription($payment, $options);
        });
    }

    /** @param  \Closure(PaymentGatewayContract, ChargeOptions): PaymentResult  $issue */
    protected function openCheckout(Payment $payment, ChargeOptions $options, \Closure $issue): PaymentResult
    {
        $driver = $this->driver($payment->gateway, $payment->billable?->tenantId());

        if ($options->receiptItems === [] && $payment->payable instanceof HasReceiptItems) {
            $options = $options->withReceiptItems($payment->payable->receiptItems());
        }

        $this->assertReceiptItemsMatchAmount($payment, $options);
        $options = $this->withTenantHint($payment, $options);

        $result = $issue($driver, $options);

        $url = $result->url;
        $urlExpiresAt = $result->expiresAt;

        if ($url === null && $result->form !== null) {
            $urlExpiresAt ??= now()->addHour();
            Cache::put("billing.checkout_form.{$payment->id}", $result->form, $urlExpiresAt);
            $url = route('billing.checkout-form', $payment);
        }

        $payment->fill([
            'external_id' => $result->externalId ?? $payment->external_id,
            // A checkout is something a person opened; only an explicit override says otherwise
            // (e.g. a dunning retry the consumer's own scheduler pushes through this method).
            'initiation' => $options->initiation ?? PaymentInitiation::Manual,
            'payment_url' => $url,
            'payment_url_expires_at' => $urlExpiresAt,
            // Kept for support/debugging: without it the gateway's own account of what it did with
            // this charge exists nowhere (a webhook only ever reports the outcome).
            'raw_response' => $result->raw !== [] ? $result->raw : $payment->raw_response,
            // A fresh invoice means this payment is awaiting its outcome again. Without the reset a
            // re-issue through billing.pay leaves a canceled/failed row pointing at a LIVE checkout,
            // and reconcile-pending-payments only ever polls Pending ones — so a lost success
            // webhook would strand a paid invoice for good, money taken and nothing delivered.
            // Paid is never reopened: that row's outcome is already known.
            ...($payment->status === PaymentStatus::Paid ? [] : ['status' => PaymentStatus::Pending]),
        ])->save();

        return $result;
    }

    /**
     * Same orchestration as charge(), for a saved payment method instead of a redirect/form —
     * including the same $options->receiptItems auto-fill (see charge()'s docblock). The auto-fill
     * never triggers for a scheduled subscription renewal: its Payable is always the package's own
     * Subscription row, which deliberately does NOT implement HasReceiptItems (the basket total
     * would have to reconstruct pricing_type/currency-conversion math the package doesn't want to
     * guess at for a fiscal document). A renewal's options come from
     * Contracts\RenewalChargeOptionsContract instead — see ProcessRecurringChargesCommand.
     */
    public function chargeWithMethod(Payment $payment, PaymentMethod $method, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        // Cheap guards against a caller-side mixup that would debit the wrong card: the method
        // must be from the same gateway AND belong to the same billable as the payment.
        if ($method->gateway !== $payment->gateway) {
            throw new BillingException("Payment method belongs to gateway \"{$method->gateway}\", the payment to \"{$payment->gateway}\".");
        }

        if ($method->billable_type !== $payment->billable_type || (string) $method->billable_id !== (string) $payment->billable_id) {
            throw new BillingException("Payment method {$method->id} does not belong to payment {$payment->id}'s billable.");
        }

        $driver = $this->driver($payment->gateway, $payment->billable?->tenantId());

        if (! $driver instanceof TokenizesPaymentMethod) {
            throw NotSupportedException::forCapability($payment->gateway, TokenizesPaymentMethod::class);
        }

        if ($options->receiptItems === [] && $payment->payable instanceof HasReceiptItems) {
            $options = $options->withReceiptItems($payment->payable->receiptItems());
        }

        $this->assertReceiptItemsMatchAmount($payment, $options);
        $options = $this->withTenantHint($payment, $options);

        $result = $driver->chargePaymentMethod($payment, $method, $options);

        $payment->fill([
            'external_id' => $result->externalId ?? $payment->external_id,
            // Off-session by default — this is the path a scheduled renewal takes. A one-click
            // "pay with the saved card" is the same call with a Manual override.
            'initiation' => $options->initiation ?? PaymentInitiation::Automatic,
            'payment_url' => $result->url,
            'payment_url_expires_at' => $result->expiresAt,
            // The decline reason lives here and nowhere else: an off-session charge the gateway
            // refuses synchronously (Stripe's error.code, Hutko's error_message, WayForPay's
            // reasonCode) never produces a webhook to carry it.
            'raw_response' => $result->raw !== [] ? $result->raw : $payment->raw_response,
        ])->save();

        return $result;
    }

    /**
     * Stamps the billable's tenant onto the callback URL the gateway is about to be given, unless
     * the caller already set one. Without it a multi-tenant app works in one direction only:
     * outgoing calls resolve credentials by tenant, while the incoming webhook — which has to pick
     * a secret BEFORE it can verify anything — falls back to the default tenant and answers 403.
     * Apps on the default resolver never notice: it ignores the tenant either way.
     */
    protected function withTenantHint(Payment $payment, ChargeOptions $options): ChargeOptions
    {
        $tenantId = $payment->billable?->tenantId();

        if ($tenantId === null || isset($options->webhookUrlParams[WebhookTenant::QUERY_KEY])) {
            return $options;
        }

        return new ChargeOptions(...[
            'webhookUrlParams' => [...$options->webhookUrlParams, WebhookTenant::QUERY_KEY => $tenantId],
        ] + get_object_vars($options));
    }

    /**
     * A basket that doesn't add up to the Payment's own amount is a bug worth stopping the charge
     * over, not a rounding curiosity: Stripe bills the sum of its line_items rather than our
     * amount, so the customer would be charged something other than what the row says — and the
     * webhook, checking the callback against amount, would then refuse to mark it paid, leaving a
     * pending row for money that actually left the customer's card.
     */
    protected function assertReceiptItemsMatchAmount(Payment $payment, ChargeOptions $options): void
    {
        if ($options->receiptItems === []) {
            return;
        }

        $total = 0;

        foreach ($options->receiptItems as $item) {
            $total += (int) round($item['unitAmount'] * $item['qty']);
        }

        if ($total !== $payment->amount) {
            throw new BillingException(
                "Receipt items for payment {$payment->id} total {$total}, the payment is {$payment->amount} {$payment->currency}."
            );
        }
    }

    /**
     * Live "credentials valid + API reachable" probe — for a settings-UI "test connection" button
     * or a monitoring cron (see the billing:health command). Never a guarantee about the next
     * charge; never has side effects on the gateway.
     */
    public function health(string $gateway, ?string $tenantId = null): \Fomvasss\Billing\DTO\GatewayHealth
    {
        $driver = $this->driver($gateway, $tenantId);

        if (! $driver instanceof \Fomvasss\Billing\Contracts\ChecksGatewayHealth) {
            throw NotSupportedException::forCapability($gateway, \Fomvasss\Billing\Contracts\ChecksGatewayHealth::class);
        }

        return $driver->healthCheck();
    }

    /**
     * The orchestration half of RefundsPayments — drivers only make the API call; this creates the
     * child Payment row (type=refund, parent_payment_id) and dispatches PaymentRefunded, so
     * refundedAmount() and the event actually reflect what happened. $amount null = refund the
     * unrefunded remainder in full.
     */
    public function refund(Payment $payment, ?Money $amount = null): Payment
    {
        $driver = $this->driver($payment->gateway, $payment->billable?->tenantId());

        if (! $driver instanceof RefundsPayments) {
            throw NotSupportedException::forCapability($payment->gateway, RefundsPayments::class);
        }

        // "How much is left to refund" is read, checked and then written by a THIRD statement (the
        // child row) — two concurrent calls (an impatient double click, a retried job) would both
        // pass the remainder check against the same stale total and both send money back. The lock
        // is only as wide as the app's cache store: redis/memcached/database reach every process on
        // every server, `file` reaches every process on one machine (it locks with flock()) but not
        // across app servers, and `array` protects nothing.
        $lock = Cache::lock("billing:refund:{$payment->id}", 60);

        if (! $lock->get()) {
            throw new BillingException("Another refund for payment {$payment->id} is already in progress.");
        }

        try {
            return $this->processRefund($driver, $payment->fresh(), $amount);
        } finally {
            $lock->release();
        }
    }

    protected function processRefund(RefundsPayments $driver, Payment $payment, ?Money $amount): Payment
    {
        if (! $payment->isPaid() || $payment->isRefund()) {
            throw new BillingException("Only a paid charge can be refunded (payment {$payment->id} is {$payment->type->value}/{$payment->status->value}).");
        }

        $money = $amount ?? new Money($payment->refundableRemainder(), $payment->currency);

        if ($money->currency !== $payment->currency) {
            throw new BillingException("Refund currency \"{$money->currency}\" does not match the charge's \"{$payment->currency}\".");
        }

        if ($money->amount <= 0 || $money->amount > $payment->refundableRemainder()) {
            throw new BillingException("Refund of {$money->amount} exceeds the refundable remainder of payment {$payment->id}.");
        }

        // Always an explicit amount, even for "full": with earlier partial refunds, a null/full
        // gateway-side refund and our computed remainder would disagree. A gateway that refuses the
        // refund throws from here — the child row below is only ever written for money that is
        // actually on its way back.
        $result = $driver->refund($payment, $money);

        // Awaiting the gateway's approval: recorded so the amount is reserved, but nothing has been
        // returned yet — PaymentRefunded fires when the driver's webhook reports the approval.
        if ($result->pending) {
            return Payment::recordRefundOf($payment, $money, $result->externalId, $result->raw, PaymentStatus::Pending);
        }

        $refund = Payment::recordRefundOf($payment, $money, $result->externalId, $result->raw);

        // Claimed under the same dedup key the driver's webhook path uses for this row, so the
        // gateway's callback echoing this refund finds the outcome already dispatched.
        WebhookResultDispatcher::dispatchOnce($payment->gateway, new WebhookResult(
            type: WebhookEventType::Payment,
            status: 'refunded',
            payment: $refund,
            externalId: (string) $refund->id,
        ), source: 'refund');

        return $refund;
    }

    /**
     * The 4-step order from "Валюти" in the plan: (1) $price's own currency already accepted by
     * $gateway → as-is; (2) a sibling Price of the same Plan+gateway in an accepted currency →
     * that one instead; (3) CurrencyConverterContract bound → convert; (4) none of the above →
     * BillingException::unsupportedCurrency(). Only resolves the CURRENCY/per-unit rate — scaling
     * by qty (licensed) or current_usage (metered) is the caller's job (Price::amountForSubscription()),
     * done on top of whatever Money this returns.
     */
    public function resolveChargeAmount(Price $price, string $gateway): ResolvedAmount
    {
        $supported = $this->supportedCurrencies($gateway);

        if (in_array($price->currency, $supported, true)) {
            return new ResolvedAmount(new Money($price->amount, $price->currency));
        }

        // A gateway-specific sibling wins; a generic (gateway=null) price in an accepted currency
        // is still better than paying for a conversion. "Sibling" means the SAME offer in another
        // currency, so the billing cycle and pricing model have to match: without that, a plan
        // priced monthly in UAH and yearly in USD would quietly bill a monthly subscription the
        // yearly amount. Retired prices are excluded for the same reason.
        $siblings = fn () => $price->plan
            ->prices()
            ->whereIn('currency', $supported)
            ->where('interval', $price->interval)
            // ?? 1 mirrors the column default: a Price created and used without a round trip to
            // the database still carries null here, and null would match nothing.
            ->where('interval_count', $price->interval_count ?? 1)
            ->where('pricing_type', $price->pricing_type)
            ->where('is_active', true);

        $sibling = $siblings()->where('gateway', $gateway)->first()
            ?? $siblings()->whereNull('gateway')->first();

        if ($sibling !== null) {
            return new ResolvedAmount(new Money($sibling->amount, $sibling->currency));
        }

        if (app()->bound(CurrencyConverterContract::class)) {
            $toCurrency = $supported[0] ?? throw BillingException::unsupportedCurrency($price->currency, $gateway);

            $original = new Money($price->amount, $price->currency);
            $converted = app(CurrencyConverterContract::class)->convert($original, $toCurrency);

            return new ResolvedAmount(
                money: $converted,
                convertedFromCurrency: $price->currency,
                exchangeRate: $original->amount > 0 ? $converted->amount / $original->amount : null,
                exchangeRateAt: now(),
            );
        }

        throw BillingException::unsupportedCurrency($price->currency, $gateway);
    }

    /**
     * An invoice — a bill to pay — for a payment that is still to be paid: the customer pays it
     * through the permanent pay link printed on it (billing.pay), or by bank transfer to the
     * seller's details. For a payment already paid it comes out paid, without a due date or a pay
     * link — the invoice accounting asks for after the fact — and without a subscription's period,
     * which by then the renewal has moved on (see invoiceAtPayment()). Everything it shows is a snapshot taken now: items (the payable's
     * receiptItems(), or one line for the whole amount), seller ($seller, else InvoiceSellerContract
     * — the gateway's own `seller`, else the general one), buyer ($buyer, else the billable's
     * billingDetails()). Idempotent: a payment has one invoice, and asking again returns it.
     *
     * @param  array<string, mixed>  $extra  anything your templates print that the snapshot doesn't carry (contract number, notes)
     */
    public function issueInvoice(Payment $payment, ?BillingDetails $buyer = null, array $extra = [], ?\DateTimeInterface $dueAt = null, ?string $locale = null, ?BillingDetails $seller = null): Invoice
    {
        if ($payment->isRefund()) {
            throw new BillingException("Payment {$payment->id} is a refund — invoices are for charges.");
        }

        $dueDays = $payment->isPaid() ? null : config('billing.invoices.due_days');

        return $this->issueDocument(
            InvoiceType::Invoice,
            $payment,
            $buyer,
            $extra,
            $dueAt ?? ($dueDays !== null ? now()->addDays((int) $dueDays) : null),
            $locale,
            seller: $seller,
        );
    }

    /**
     * The invoice `auto_invoice` issues on PaymentSucceeded, for a charge paid without one. Unlike
     * issueInvoice() on a paid payment it names a subscription's period: it runs before the renewal
     * moves the subscription on, so the current period's end is where the paid one starts.
     *
     * @internal called by SettleInvoiceOnPayment
     */
    public function invoiceAtPayment(Payment $payment): Invoice
    {
        return $this->issueDocument(InvoiceType::Invoice, $payment, null, [], null, null, atPayment: true);
    }

    /**
     * A receipt — proof of payment — for a paid charge. Issued automatically on PaymentSucceeded
     * when `billing.invoices.auto_receipt` is on; call it yourself for a payment recorded as paid
     * by hand (which fires no event). When the payment had an invoice, the receipt settles it:
     * same seller, items and buyer, and it points back at the invoice. Idempotent — one receipt
     * per payment.
     */
    public function issueReceipt(Payment $payment, ?BillingDetails $buyer = null, array $extra = [], ?string $locale = null, ?BillingDetails $seller = null): Invoice
    {
        if ($payment->isRefund() || ! $payment->isPaid()) {
            throw new BillingException("Payment {$payment->id} is {$payment->type->value}/{$payment->status->value} — a receipt is for a paid charge.");
        }

        $invoice = Invoice::query()->where('type', InvoiceType::Invoice)->where('payment_id', $payment->id)->first();

        return $this->issueDocument(
            InvoiceType::Receipt,
            $payment,
            $buyer ?? $invoice?->buyerDetails(),
            [...($invoice?->extra ?? []), ...$extra],
            null,
            $locale ?? $invoice?->locale,
            $invoice?->items,
            $invoice,
            // the invoice's seller, not a fresh lookup — the receipt is the same deal, even if the
            // seller's details changed since the invoice went out
            $seller ?? $invoice?->sellerDetails(),
        );
    }

    /** Withdraws an unpaid invoice. It keeps its number — numbers are never reused. */
    public function voidInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->isPaid()) {
            throw new BillingException("Invoice {$invoice->number} is paid — refund the payment instead of voiding the document.");
        }

        $invoice->update(['status' => InvoiceStatus::Void]);

        return $invoice;
    }

    /**
     * What a template sees as `$document` — the snapshot, formatted in the document's locale. For
     * anything printed from the same data outside the PDF: an email summary, a cabinet page.
     */
    public function invoiceDocument(Invoice $invoice): InvoiceDocument
    {
        return InvoiceDocumentFactory::make($invoice);
    }

    /** The document as HTML, in its own locale — for a preview, an email body, or a custom PDF pipeline. */
    public function renderInvoice(Invoice $invoice): string
    {
        $document = $this->invoiceDocument($invoice);
        $previous = app()->getLocale();

        app()->setLocale($document->locale);

        try {
            return view(app(InvoiceTemplateResolver::class)->view($invoice), [
                ...app(InvoiceViewDataContract::class)->data($invoice),
                'document' => $document,
                'invoice' => $invoice,
            ])->render();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * The document as PDF bytes (InvoiceRenderer — dompdf by default). Generated on the fly from the
     * snapshot; `billing.invoices.storage` (disk + path) keeps a copy, keyed by status so a paid
     * invoice doesn't keep serving its "unpaid" copy.
     */
    public function invoicePdf(Invoice $invoice): string
    {
        $disk = config('billing.invoices.storage.disk');
        $path = trim((string) config('billing.invoices.storage.path', 'billing/invoices'), '/')
            ."/{$invoice->id}-{$invoice->status->value}.pdf";

        if ($disk !== null && Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->get($path);
        }

        $pdf = app(InvoiceRenderer::class)->pdf($this->renderInvoice($invoice));

        if ($disk !== null) {
            Storage::disk($disk)->put($path, $pdf);
        }

        return $pdf;
    }

    /**
     * A temporary signed link to the PDF — for an email. Whoever holds it opens the document until
     * it expires (a forwarded email included); add `billing.invoices.pdf_middleware` to require
     * more, or turn the route off (`pdf_route`) and serve documents from your own authenticated
     * route with invoicePdf().
     */
    public function invoicePdfUrl(Invoice $invoice, ?int $ttlMinutes = null): string
    {
        if (! config('billing.invoices.pdf_route', true)) {
            throw new BillingException('The signed PDF route is off (billing.invoices.pdf_route) — serve the document from your own route with Billing::invoicePdf().');
        }

        return URL::temporarySignedRoute(
            'billing.invoices.pdf',
            now()->addMinutes($ttlMinutes ?? (int) config('billing.invoices.link_ttl_minutes', 10080)),
            ['invoice' => $invoice],
        );
    }

    /**
     * Number and row in one transaction: a receipt racing its twin (the listener and a manual call)
     * loses on the unique (type, payment_id) index and rolls its number back with it — no gap in
     * the series — then returns the document that won.
     */
    protected function issueDocument(
        InvoiceType $type,
        Payment $payment,
        ?BillingDetails $buyer,
        array $extra,
        ?\DateTimeInterface $dueAt,
        ?string $locale,
        ?array $items = null,
        ?Invoice $invoice = null,
        ?BillingDetails $seller = null,
        bool $atPayment = false,
    ): Invoice {
        $existing = fn () => Invoice::query()->where('type', $type)->where('payment_id', $payment->id)->first();

        if ($found = $existing()) {
            return $found;
        }

        $billable = $payment->billable;
        $buyer ??= $billable instanceof HasBillingDetails ? $billable->billingDetails() : new BillingDetails(name: '');
        $locale ??= (string) config('billing.invoices.locale', app()->getLocale());
        $extra = $this->withSubscriptionSnapshot($payment, $type, $extra, $atPayment);

        // How it was paid — named at issue time, so a receipt keeps saying "Monobank" even if the
        // gateway is later renamed or removed.
        if ($type === InvoiceType::Receipt && ! isset($extra['payment_method'])) {
            $extra['payment_method'] = $payment->gateway !== null
                ? ($this->gateway($payment->gateway)['label'] ?? $payment->gateway)
                : trans('billing::invoice.method_manual', [], $locale);
        }

        $items ??= $this->documentItems($payment, $locale, $extra['subscription'] ?? null);

        try {
            $document = DB::transaction(fn () => Invoice::create([
                'type' => $type,
                'status' => $type === InvoiceType::Receipt || $payment->isPaid() ? InvoiceStatus::Paid : InvoiceStatus::Issued,
                'number' => DocumentNumber::next(
                    $type->series(),
                    config('billing.invoices.number_per_tenant', true) ? $payment->tenant_id : null,
                    (string) config("billing.invoices.number_format.{$type->value}", $type->series().'-{Y}-{000000}'),
                ),
                'series' => $type->series(),
                'currency' => $payment->currency,
                'total' => $payment->amount,
                'seller' => ($seller ?? app(InvoiceSellerContract::class)->seller($payment->gateway, $payment->tenant_id))->toArray(),
                'buyer' => $buyer->toArray(),
                'items' => $items,
                'extra' => $extra ?: null,
                'locale' => $locale,
                'issued_at' => now(),
                'due_at' => $dueAt,
                'paid_at' => $type === InvoiceType::Receipt || $payment->isPaid() ? ($payment->paid_at ?? now()) : null,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice?->id,
                'tenant_id' => $payment->tenant_id,
                'billable_type' => $payment->billable_type,
                'billable_id' => $payment->billable_id,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            return $existing() ?? throw $exception;
        }

        InvoiceIssued::dispatch($document);

        return $document;
    }

    /**
     * A document for a subscription payment records what was bought — the plan's name, and on an
     * invoice the date the paid period will run to — under `extra['subscription']`, so a later plan
     * swap or renewal doesn't change a document already sent. A receipt settling an invoice already
     * carries the invoice's (its extra is copied). The period is left out where the package can't
     * be sure of it at issue time: a receipt without an invoice and an invoice issued after the
     * payment (the subscription may already have moved on), and a provider-managed subscription
     * (the provider sets its periods). A
     * `subscription` key you passed yourself wins.
     */
    protected function withSubscriptionSnapshot(Payment $payment, InvoiceType $type, array $extra, bool $atPayment = false): array
    {
        $subscription = $payment->payable;

        if (! $subscription instanceof Subscription || isset($extra['subscription'])) {
            return $extra;
        }

        $knowsPeriod = $type === InvoiceType::Invoice
            && ! $subscription->isProviderManaged()
            && ($atPayment || ! $payment->isPaid());
        $periodEnd = $knowsPeriod ? $subscription->nextPeriodEnd() : null;

        return [...$extra, 'subscription' => array_filter([
            'plan' => $subscription->price?->plan?->name,
            // The paid period starts where the current one ends — or now, for a first payment.
            'period_starts_at' => $periodEnd !== null ? ($subscription->current_period_ends_at ?? now())->toDateString() : null,
            'period_ends_at' => $periodEnd?->toDateString(),
        ])];
    }

    /**
     * The lines from InvoiceItemsContract (the payable's receipt items by default, checked against
     * the amount), else one line for the whole payment. A subscription document's paid period goes
     * on every line that doesn't carry its own.
     */
    protected function documentItems(Payment $payment, string $locale, ?array $subscription = null): array
    {
        $items = app(InvoiceItemsContract::class)->items($payment);
        $period = isset($subscription['period_starts_at'], $subscription['period_ends_at'])
            ? ['period' => ['starts_at' => $subscription['period_starts_at'], 'ends_at' => $subscription['period_ends_at']]]
            : [];

        if ($items === []) {
            $plan = $payment->payable instanceof Subscription ? $payment->payable->price?->plan?->name : null;

            return [[
                // What was bought when the package knows it (a subscription's plan), else the
                // payment's number — never its uuid, which means nothing on paper.
                'name' => match (true) {
                    $plan !== null => trans('billing::invoice.subscription_line', ['plan' => $plan], $locale),
                    $payment->number !== null => trans('billing::invoice.payment', ['number' => $payment->number], $locale),
                    default => trans('billing::invoice.payment_default', [], $locale),
                },
                'qty' => 1,
                'unitAmount' => $payment->amount,
                'total' => $payment->amount,
                // The period the line pays for, printed under it — only where it's known for sure.
                ...$period,
            ]];
        }

        $this->assertReceiptItemsMatchAmount($payment, new ChargeOptions(receiptItems: $items));

        return array_map(fn (array $item) => [
            ...$period,
            ...$item,
            'total' => (int) round($item['unitAmount'] * $item['qty']),
        ], $items);
    }
}
