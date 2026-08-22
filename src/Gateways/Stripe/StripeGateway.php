<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Stripe;

use Fomvasss\Billing\Contracts\Billable;
use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\Contracts\RefundsPayments;
use Fomvasss\Billing\Contracts\TokenizesPaymentMethod;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Events\PaymentMethodAttached;
use Fomvasss\Billing\Events\PaymentMethodDetached;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Gateways\AbstractGateway;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;

/**
 * https://api.stripe.com/v1 — Checkout Sessions (mode=payment) for the redirect flow,
 * PaymentIntents + PaymentMethods for saved-card/off-session charges — verified against the
 * official docs (docs.stripe.com/api/checkout/sessions/create, docs.stripe.com/api/payment_intents/create,
 * docs.stripe.com/api/payment_methods/attach, docs.stripe.com/webhooks/signatures).
 *
 * `amount` — minor units (cents), same convention our own Payment.amount already uses, no
 * conversion needed (unlike LiqPay/WayForPay).
 *
 * metadata.payment_id is set on the Checkout Session, its PaymentIntent (payment_intent_data.metadata
 * — Stripe doesn't propagate Session metadata to the PaymentIntent automatically), and on
 * chargePaymentMethod()'s own PaymentIntent — checkout.session.*, payment_intent.succeeded and
 * payment_intent.payment_failed all need it to look our Payment row back up.
 */
class StripeGateway extends AbstractGateway implements RefundsPayments, ChecksPaymentStatus, TokenizesPaymentMethod, \Fomvasss\Billing\Contracts\ChecksGatewayHealth
{
    protected const BASE_URL = 'https://api.stripe.com/v1';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        // saveCard without any frontend JS: the hosted Checkout saves the card to our per-billable
        // customer via setup_future_usage=off_session (the same "tokenize as a side effect of the
        // first charge" flow the UA gateways have; confirmed by greespi's production Stripe
        // subscriptions). handleWebhook() then persists the PaymentMethod from the session's
        // payment intent. Stripe rejects customer+customer_email together — customer wins here.
        $customerId = $options->saveCard && $payment->billable instanceof Model && $payment->billable instanceof Billable
            ? $this->resolveCustomerId($payment->billable)
            : null;

        $response = $this->http()->asForm()->post('/checkout/sessions', array_filter([
            // Gateway-specific extras (automatic_tax, custom_fields, ...). Merged first so the
            // driver's own fields below always win — raw adds, never overrides amount/metadata.
            ...$options->raw,
            'mode' => 'payment',
            'line_items' => $this->lineItems($payment, $options),
            'success_url' => $this->successUrl($payment, $options),
            'cancel_url' => $this->failUrl($payment, $options),
            'customer' => $customerId,
            'customer_email' => $customerId === null ? $options->customerEmail : null,
            'client_reference_id' => (string) $payment->id,
            'metadata' => ['payment_id' => (string) $payment->id],
            'payment_intent_data' => array_filter([
                'metadata' => ['payment_id' => (string) $payment->id],
                'setup_future_usage' => $customerId !== null ? 'off_session' : null,
            ]),
            'locale' => $options->locale,
        ]))->throw();

        $data = $response->json();

        return new PaymentResult(
            url: $data['url'],
            expiresAt: isset($data['expires_at']) ? Carbon::createFromTimestamp($data['expires_at']) : null,
            externalId: $data['id'],
            raw: $data,
        );
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $event = $webhookCall->payload;
        $object = $event['data']['object'] ?? [];

        $paymentId = $object['metadata']['payment_id'] ?? $object['client_reference_id'] ?? null;

        if ($paymentId === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        $status = match ($event['type'] ?? null) {
            'checkout.session.completed' => ($object['payment_status'] ?? null) === 'paid' ? PaymentStatus::Paid : null,
            'checkout.session.expired' => PaymentStatus::Canceled,
            // A raw off-session PaymentIntent (chargePaymentMethod()) never goes through Checkout,
            // so it has no checkout.session.* counterpart — payment_intent.succeeded is the only
            // signal for it. Also fires alongside checkout.session.completed for the redirect flow
            // (the transitionTo() below is idempotent, so processing both is harmless).
            'payment_intent.succeeded' => PaymentStatus::Paid,
            // Only terminal for a raw off-session intent. Inside a Checkout Session the customer is
            // still on the page and free to try another card, so this fires on a declined FIRST
            // attempt of a checkout that may well end up paid — treating it as failed would put the
            // subscription into dunning (and let billing.pay issue a competing second session)
            // while the original one is still live. checkout.session.expired is that flow's
            // terminal signal; see paymentIntentIsCheckoutBound().
            'payment_intent.payment_failed' => $this->paymentIntentIsCheckoutBound($object) ? null : PaymentStatus::Failed,
            default => null,
        };

        // A refund issued from the Stripe dashboard, or forced by a dispute — money that left the
        // account without going through Billing::refund(). `amount_refunded` on the Charge is
        // Stripe's own running total, which is what makes a re-delivery settle instead of stack.
        if (($event['type'] ?? null) === 'charge.refunded') {
            return $this->recordRefundFromWebhook($object, $event);
        }

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        // An event for a payment this package didn't create is Ignored, not a failed job.
        $payment = $this->findPaymentByReference($paymentId);

        if ($payment === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        // amount_total on a Checkout Session, amount on a bare PaymentIntent — both minor units.
        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($object['amount_total']) || isset($object['amount']) ? (int) ($object['amount_total'] ?? $object['amount']) : null,
            $object['currency'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        // The saveCard flow's second half: a paid session carrying a customer means charge() ran
        // with setup_future_usage — pull the payment method off the session's intent and persist
        // it (see attachFromCheckoutSession()).
        if ($status === PaymentStatus::Paid
            && ($event['type'] ?? null) === 'checkout.session.completed'
            && ! empty($object['customer'])
            && ! empty($object['payment_intent'])) {
            $this->attachFromCheckoutSession($payment, (string) $object['customer'], (string) $object['payment_intent']);
        }

        $externalId = $object['payment_intent'] ?? $object['id'] ?? null;

        if (! $payment->transitionTo($status, array_filter(['external_id' => $externalId]))) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: match ($status) {
                PaymentStatus::Paid => 'succeeded',
                PaymentStatus::Failed => 'failed',
                default => 'canceled',
            },
            payment: $payment,
            externalId: $externalId ?? (string) $payment->id,
            raw: $event,
        );
    }

    protected function recordRefundFromWebhook(array $object, array $event): WebhookResult
    {
        $paymentId = $object['metadata']['payment_id'] ?? null;
        $charge = $paymentId === null ? null : $this->findPaymentByReference($paymentId);

        if ($charge === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        $refund = $this->recordExternalRefund(
            $charge,
            isset($object['amount_refunded']) ? (int) $object['amount_refunded'] : null,
            $object['refunds']['data'][0]['id'] ?? null,
            $event,
        );

        if ($refund === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: 'refunded',
            payment: $refund,
            externalId: $refund->external_id ?? "refund:{$charge->id}:{$refund->amount}",
            raw: $event,
        );
    }

    /**
     * Whether this PaymentIntent belongs to a hosted Checkout Session rather than being one we
     * created directly. Stripe doesn't put the session on the intent, so the tell is our own
     * bookkeeping: chargePaymentMethod() is the only path that stores a `pi_` external_id up front
     * — a row still holding its `cs_` session id (or nothing yet) is a checkout in progress.
     */
    protected function paymentIntentIsCheckoutBound(array $object): bool
    {
        $paymentId = $object['metadata']['payment_id'] ?? $object['client_reference_id'] ?? null;
        $payment = $paymentId === null ? null : $this->findPaymentByReference($paymentId);

        return $payment !== null && ! str_starts_with((string) $payment->external_id, 'pi_');
    }

    public function refund(Payment $payment, ?Money $amount = null): PaymentResult
    {
        // A fresh key per call, not one derived from the payment: two deliberate partial refunds of
        // the same amount must both go through, while http()'s retry (which fires on a timeout too,
        // when Stripe may already have refunded) must not return the money twice.
        $response = $this->http()
            ->withHeaders(['Idempotency-Key' => 'refund-' . Str::uuid()->toString()])
            ->asForm()
            ->post('/refunds', array_filter([
                'payment_intent' => $payment->external_id,
                'amount' => $amount?->amount,
            ]))->throw();

        $data = $response->json();

        if (in_array($data['status'] ?? null, ['failed', 'canceled'], true)) {
            throw new BillingException('Stripe: refund was refused: ' . json_encode($data));
        }

        // The refund's own id (re_...), not the charge's PaymentIntent — the child row's
        // external_id has to identify the refund for a support lookup to land anywhere useful.
        return new PaymentResult(externalId: $data['id'] ?? $payment->external_id, raw: $data);
    }

    public function createCustomer(Model&Billable $billable): string
    {
        $data = $this->http()->asForm()->post('/customers', [
            'metadata' => [
                'billable_type' => $billable::class,
                'billable_id' => (string) $billable->getKey(),
            ],
        ])->throw()->json();

        return $data['id'];
    }

    /** $token = ['payment_method_id' => 'pm_...'] — from Stripe.js/Elements confirming a SetupIntent on the frontend. */
    public function attachPaymentMethod(Model&Billable $billable, array $token): PaymentMethod
    {
        $paymentMethodId = $token['payment_method_id']
            ?? throw new BillingException('Stripe: token must include "payment_method_id" (pm_...).');

        $customerId = $this->resolveCustomerId($billable);

        $data = $this->http()->asForm()
            ->post("/payment_methods/{$paymentMethodId}/attach", ['customer' => $customerId])
            ->throw()
            ->json();

        // Not required for the charge itself, but keeps Stripe's own "default payment method for
        // this customer" in sync with ours (relevant if this customer is ever charged via the
        // Stripe Dashboard or Invoicing directly, outside this package).
        $this->http()->asForm()
            ->post("/customers/{$customerId}", ['invoice_settings' => ['default_payment_method' => $paymentMethodId]])
            ->throw();

        $method = $this->persistPaymentMethod(
            $billable::class,
            (string) $billable->getKey(),
            $billable->tenantId(),
            $customerId,
            $paymentMethodId,
            $data['card']['last4'] ?? null,
            $data['card']['brand'] ?? null,
            isset($data['card']['exp_year'], $data['card']['exp_month'])
                ? Carbon::createFromDate((int) $data['card']['exp_year'], (int) $data['card']['exp_month'], 1)->endOfMonth()
                : null,
        );

        PaymentMethodAttached::dispatch($method);

        return $method;
    }

    /**
     * Off-session charge against an already-attached PaymentMethod — the actual outcome
     * (succeeded/requires 3DS/declined) still arrives through handleWebhook()
     * (payment_intent.succeeded/payment_intent.payment_failed), same as every other Payment; this
     * call only initiates it. A card-error response (decline, authentication_required, ...) is a
     * normal business outcome, not a wiring failure, so it's returned rather than thrown — anything
     * else (bad credentials, malformed request) still throws.
     */
    /**
     * $options->receiptItems is unused here — unlike Checkout Sessions (charge()'s line_items),
     * the PaymentIntents API this off-session charge goes through has no basket/line-item concept
     * at all, only an amount. ChargeOptions is still the parameter type for interface parity with
     * every other driver's chargePaymentMethod().
     */
    public function chargePaymentMethod(Payment $payment, PaymentMethod $method, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        // retry(1) overrides http()'s default retry(2, 200) — a card decline is a normal business
        // outcome to inspect below, not a transient failure worth retrying. The idempotency key is
        // the payment's own id: every renewal attempt gets a fresh Payment row, so it never
        // collapses two intended charges, but a caller retrying the same row after a timeout gets
        // Stripe's original PaymentIntent back instead of a second debit.
        $response = $this->http()
            ->withHeaders(['Idempotency-Key' => 'charge-' . $payment->id])
            ->retry(1)
            ->asForm()
            ->post('/payment_intents', array_filter([
            'amount' => $payment->amount,
            'currency' => strtolower($payment->currency),
            'customer' => $method->external_customer_id,
            'payment_method' => $method->external_id,
            'off_session' => 'true', // asForm() turns PHP true into "1", which Stripe's form encoding rejects ("Invalid boolean: 1") — live-found
            'confirm' => 'true',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]));

        $data = $response->json();

        if ($response->failed() && ($data['error']['type'] ?? null) !== 'card_error') {
            $response->throw();
        }

        $externalId = $data['id'] ?? $data['error']['payment_intent']['id'] ?? null;

        return new PaymentResult(externalId: $externalId, raw: $data);
    }

    public function detachPaymentMethod(PaymentMethod $method): void
    {
        $this->http()->asForm()->post("/payment_methods/{$method->external_id}/detach")->throw();

        $method->delete();

        PaymentMethodDetached::dispatch($method);
    }

    public function checkStatus(Payment $payment): WebhookResult
    {
        // external_id starts out as the Checkout Session id, but becomes the PaymentIntent id once
        // a webhook lands — and is a PI from the very start for off-session chargePaymentMethod()
        // payments. Poll whichever object it actually is.
        if (str_starts_with((string) $payment->external_id, 'pi_')) {
            return $this->checkPaymentIntentStatus($payment);
        }

        $data = $this->http()->get("/checkout/sessions/{$payment->external_id}")->throw()->json();

        $status = match (true) {
            $data['status'] === 'complete' && ($data['payment_status'] ?? null) === 'paid' => PaymentStatus::Paid,
            $data['status'] === 'expired' => PaymentStatus::Canceled,
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($data['amount_total']) ? (int) $data['amount_total'] : null,
            $data['currency'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        $externalId = $data['payment_intent'] ?? $data['id'];

        if (! $payment->transitionTo($status, ['external_id' => $externalId])) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: $status === PaymentStatus::Paid ? 'succeeded' : 'canceled',
            payment: $payment,
            externalId: $externalId,
            raw: $data,
        );
    }

    protected function checkPaymentIntentStatus(Payment $payment): WebhookResult
    {
        $data = $this->http()->get("/payment_intents/{$payment->external_id}")->throw()->json();

        $status = match ($data['status']) {
            'succeeded' => PaymentStatus::Paid,
            'canceled' => PaymentStatus::Canceled,
            // An off-session PI dropped back to requires_payment_method is a decline whose webhook
            // never made it — terminal for reconciliation purposes.
            'requires_payment_method' => PaymentStatus::Failed,
            default => null, // processing / requires_action / requires_confirmation
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            isset($data['amount']) ? (int) $data['amount'] : null,
            $data['currency'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        if (! $payment->transitionTo($status)) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $data);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: match ($status) {
                PaymentStatus::Paid => 'succeeded',
                PaymentStatus::Failed => 'failed',
                default => 'canceled',
            },
            payment: $payment,
            externalId: $data['id'],
            raw: $data,
        );
    }

    /** GET /v1/balance — the canonical "is this key valid" probe, no side effects. */
    public function healthCheck(): \Fomvasss\Billing\DTO\GatewayHealth
    {
        return $this->probeHealth(function () {
            $data = $this->http()->retry(1)->get('/balance')->throw()->json();

            return 'livemode: ' . (($data['livemode'] ?? false) ? 'yes' : 'no (test)');
        });
    }

    public static function label(): string
    {
        return 'Stripe';
    }

    /**
     * Stripe only delivers events to PRE-REGISTERED endpoints — no per-request callback URL.
     * Registration itself works either via the Dashboard or a single POST /v1/webhook_endpoints
     * API call (which returns the whsec_ signing secret in the response) — "dashboard" in the
     * flag's name means "configured on the gateway's side ahead of time", not the UI specifically.
     */
    public static function requiresDashboardWebhook(): bool
    {
        return true;
    }

    public static function credentialFields(): array
    {
        return [
            ['name' => 'secret_key', 'type' => 'text', 'secret' => true, 'help' => 'Secret key (sk_...) з дашборду Stripe'],
            ['name' => 'webhook_secret', 'type' => 'text', 'secret' => true, 'help' => 'Signing secret (whsec_...) вебхук-ендпоінта'],
        ];
    }

    public static function supportedCurrencies(): array
    {
        // The full presentment list from docs.stripe.com/currencies (fetched 2026-08-16), MINUS
        // what this package can't represent: zero-decimal currencies (BIF, CLP, DJF, GNF, JPY,
        // KMF, KRW, MGA, PYG, RWF, UGX, VND, VUV, XAF, XOF, XPF), three-decimal ones (BHD, JOD,
        // KWD, OMR, TND) and ISK (two-decimal on the wire but fractions are rejected) — Money and
        // the drivers assume 2-decimal minor units throughout. UAH live-verified with a test-mode
        // payment. Actual availability still varies by the merchant account's country.
        return [
            'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
            'BAM', 'BBD', 'BDT', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BYN', 'BZD',
            'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CVE', 'CZK',
            'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP',
            'GBP', 'GEL', 'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF',
            'IDR', 'ILS', 'INR', 'JMD', 'KES', 'KGS', 'KHR', 'KYD', 'KZT',
            'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT', 'MOP',
            'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD',
            'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB',
            'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'STD', 'SZL',
            'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS',
            'UAH', 'USD', 'UYU', 'UZS', 'WST', 'XCD', 'XCG', 'YER', 'ZAR', 'ZMW',
        ];
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->secretKey())
            ->timeout(15)
            ->retry(2, 200);
    }

    protected function lineItems(Payment $payment, ChargeOptions $options): array
    {
        if ($options->receiptItems === []) {
            return [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payment->currency),
                    'unit_amount' => $payment->amount,
                    'product_data' => ['name' => $options->description ?? "Payment #{$payment->id}"],
                ],
            ]];
        }

        return array_map(fn (array $item) => [
            'quantity' => $item['qty'],
            'price_data' => [
                'currency' => strtolower($payment->currency),
                'unit_amount' => $item['unitAmount'],
                'product_data' => ['name' => $item['name']],
            ],
        ], $options->receiptItems);
    }

    protected function secretKey(): string
    {
        return $this->credentials['secret_key'] ?? throw new BillingException('Stripe: credential "secret_key" is missing.');
    }

    /**
     * The webhook half of the no-frontend saveCard flow: retrieves the session's PaymentIntent
     * with its payment method expanded, and persists it IF it was actually attached to the
     * customer (without setup_future_usage the pm has no customer — nothing was saved). Also
     * promotes it to the customer's default on Stripe's side, same as attachPaymentMethod().
     * Runs inside the queued webhook job, so the extra API calls are off the request path.
     */
    protected function attachFromCheckoutSession(Payment $payment, string $customerId, string $paymentIntentId): void
    {
        $pm = $this->http()
            ->get("/payment_intents/{$paymentIntentId}", ['expand' => ['payment_method']])
            ->throw()
            ->json('payment_method');

        if (! is_array($pm) || ($pm['customer'] ?? null) === null) {
            return;
        }

        $this->http()->asForm()
            ->post("/customers/{$customerId}", ['invoice_settings' => ['default_payment_method' => $pm['id']]])
            ->throw();

        $method = $this->persistPaymentMethod(
            $payment->billable_type,
            (string) $payment->billable_id,
            $payment->billable instanceof Billable ? $payment->billable->tenantId() : null,
            $customerId,
            $pm['id'],
            $pm['card']['last4'] ?? null,
            $pm['card']['brand'] ?? null,
            isset($pm['card']['exp_year'], $pm['card']['exp_month'])
                ? Carbon::createFromDate((int) $pm['card']['exp_year'], (int) $pm['card']['exp_month'], 1)->endOfMonth()
                : null,
        );

        // Direct dispatch runs BEFORE ProcessWebhookJob's dedup claim — wasRecentlyCreated keeps a
        // re-delivered event from firing PaymentMethodAttached again (same as the UA drivers).
        if ($method->wasRecentlyCreated) {
            PaymentMethodAttached::dispatch($method);
        }
    }

    /** Reuses the customer id from a previously attached method for this billable+gateway, creates one otherwise. */
    protected function resolveCustomerId(Model&Billable $billable): string
    {
        $existing = PaymentMethod::query()
            ->where('billable_type', $billable::class)
            ->where('billable_id', $billable->getKey())
            ->where('gateway', $this->gatewayName)
            ->whereNotNull('external_customer_id')
            ->value('external_customer_id');

        return $existing ?? $this->createCustomer($billable);
    }
}
