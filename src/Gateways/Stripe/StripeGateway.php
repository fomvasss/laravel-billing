<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Stripe;

use Fomvasss\Billing\Contracts\Billable;
use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\Contracts\ManagesProviderSubscriptions;
use Fomvasss\Billing\Contracts\RefundsPayments;
use Fomvasss\Billing\Contracts\ReportsTrialEnding;
use Fomvasss\Billing\Contracts\StartsProviderSubscriptions;
use Fomvasss\Billing\Contracts\TokenizesPaymentMethod;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\PaymentResult;
use Fomvasss\Billing\DTO\SubscriptionSnapshot;
use Fomvasss\Billing\DTO\WebhookResult;
use Fomvasss\Billing\Enums\Interval;
use Fomvasss\Billing\Enums\PaymentInitiation;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Enums\PaymentType;
use Fomvasss\Billing\Enums\PricingType;
use Fomvasss\Billing\Enums\SubscriptionStatus;
use Fomvasss\Billing\Enums\WebhookEventType;
use Fomvasss\Billing\Events\PaymentMethodAttached;
use Fomvasss\Billing\Events\PaymentMethodDetached;
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Exceptions\NotSupportedException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Gateways\AbstractGateway;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
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
class StripeGateway extends AbstractGateway implements RefundsPayments, ChecksPaymentStatus, TokenizesPaymentMethod, \Fomvasss\Billing\Contracts\ChecksGatewayHealth, StartsProviderSubscriptions, ManagesProviderSubscriptions, ReportsTrialEnding
{
    protected const BASE_URL = 'https://api.stripe.com/v1';

    /**
     * Pinned, not left to the account default: without it both the API responses and the webhook
     * payloads take whatever version each merchant's dashboard is set to, and Stripe's versions
     * move fields around (2025-03-31.basil took current_period_* off the subscription and the
     * PaymentIntent off the invoice). Sent on every request, and set on the webhook endpoint by
     * billing:stripe-register-webhook — events are rendered in the endpoint's version.
     */
    public const API_VERSION = '2026-08-26.dahlia';

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

    /**
     * Stripe Billing, driven through hosted Checkout (mode=subscription) — no frontend, and no
     * catalog: the price and product go inline. The payment's and the subscription's ids travel in
     * subscription_data.metadata, which Stripe copies onto the subscription and snapshots onto
     * every invoice (parent.subscription_details.metadata). A price with trial_days starts a
     * card-required free trial, recorded at 0 like Paddle's.
     */
    public function startSubscription(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        /** @var Subscription $subscription */
        $subscription = $payment->payable;
        $price = $subscription->price;
        $qty = max(1, (int) $subscription->qty);
        $trialDays = (int) ($price->trial_days ?? 0);

        if ($trialDays > 0) {
            if ($payment->amount !== 0) {
                throw new BillingException("Stripe: price {$price->id} starts with a {$trialDays}-day trial, which charges nothing at checkout — create payment {$payment->id} with amount 0.");
            }

            $unit = Billing::resolveChargeAmount($price, $this->gatewayName)->money;
        } else {
            if ($payment->amount % $qty !== 0) {
                throw new BillingException("Stripe: payment {$payment->id} amount {$payment->amount} doesn't split evenly over quantity {$qty}.");
            }

            $unit = new Money(intdiv($payment->amount, $qty), $payment->currency);
        }

        if (strcasecmp($unit->currency, $payment->currency) !== 0) {
            throw new BillingException("Stripe: payment {$payment->id} is in {$payment->currency}, the subscription price resolves to {$unit->currency}.");
        }

        $customerId = $payment->billable instanceof Model && $payment->billable instanceof Billable
            ? $this->resolveCustomerId($payment->billable)
            : null;

        $data = $this->http()->asForm()->post('/checkout/sessions', array_filter([
            ...$options->raw,
            'mode' => 'subscription',
            'line_items' => [[
                'quantity' => $qty,
                'price_data' => $this->recurringPriceData($price, $unit, product: ['name' => $options->description ?? $price->plan?->name ?? "Subscription {$subscription->id}"]),
            ]],
            'success_url' => $this->successUrl($payment, $options),
            'cancel_url' => $this->failUrl($payment, $options),
            'customer' => $customerId,
            'customer_email' => $customerId === null ? $options->customerEmail : null,
            'client_reference_id' => (string) $payment->id,
            'metadata' => ['payment_id' => (string) $payment->id],
            'subscription_data' => array_filter([
                'metadata' => ['payment_id' => (string) $payment->id, 'subscription_id' => (string) $subscription->id],
                'trial_period_days' => $trialDays > 0 ? $trialDays : null,
            ]),
            'locale' => $options->locale,
        ]))->throw()->json();

        return new PaymentResult(
            url: $data['url'],
            expiresAt: isset($data['expires_at']) ? Carbon::createFromTimestamp($data['expires_at']) : null,
            externalId: $data['id'],
            raw: $data,
        );
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd): SubscriptionSnapshot
    {
        $response = $atPeriodEnd
            ? $this->manage()->asForm()->post("/subscriptions/{$subscription->external_id}", ['cancel_at_period_end' => 'true'])
            : $this->manage()->delete("/subscriptions/{$subscription->external_id}");

        return $this->subscriptionSnapshotOrFail($response);
    }

    /**
     * Stripe's real pause (status `paused`, /pause and /resume) exists only on preview API
     * versions; pause_collection only stops invoices from being collected while the subscription
     * reads `active` — not what pause() promises. Until the pause endpoints are generally
     * available, neither is offered.
     */
    public function pause(Subscription $subscription, ?\DateTimeInterface $until): SubscriptionSnapshot
    {
        throw new NotSupportedException('Stripe: pausing a subscription needs a preview API version — not supported yet.');
    }

    public function resume(Subscription $subscription): SubscriptionSnapshot
    {
        throw new NotSupportedException('Stripe: resuming a subscription needs a preview API version — not supported yet.');
    }

    /**
     * The new price goes inline on the subscription's existing item and product — replacing the
     * item, not adding a second one (an update without the item id adds). proration_behavior
     * (credential, default create_prorations) decides what the change costs.
     */
    public function swapPrice(Subscription $subscription, Price $price): SubscriptionSnapshot
    {
        $current = $this->http()->get("/subscriptions/{$subscription->external_id}")->throw()->json();
        $item = $current['items']['data'][0] ?? throw new BillingException("Stripe: subscription {$subscription->external_id} has no item to swap.");
        $unit = Billing::resolveChargeAmount($price, $this->gatewayName)->money;

        if (strcasecmp($unit->currency, (string) ($current['currency'] ?? '')) !== 0) {
            throw new BillingException("Stripe: subscription {$subscription->id} bills in {$current['currency']}, the new price resolves to {$unit->currency}.");
        }

        $productId = $this->activeProductFor(is_array($item['price']['product'] ?? null) ? $item['price']['product']['id'] : $item['price']['product']);

        // A fresh key per call: an immediate proration invoice moves money, and a retried request
        // must not bill the change twice.
        $response = $this->manage()
            ->withHeaders(['Idempotency-Key' => 'swap-' . Str::uuid()->toString()])
            ->asForm()
            ->post("/subscriptions/{$subscription->external_id}", [
                'items' => [[
                    'id' => $item['id'],
                    'quantity' => max(1, (int) $subscription->qty),
                    'price_data' => $this->recurringPriceData($price, $unit, productId: $productId),
                ]],
                'proration_behavior' => $this->credentials['proration_behavior'] ?? 'create_prorations',
            ]);

        return $this->subscriptionSnapshotOrFail($response, $price->id);
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $event = $webhookCall->payload;
        $object = $event['data']['object'] ?? [];

        if (str_starts_with((string) ($event['type'] ?? ''), 'customer.subscription.')) {
            return $this->applySubscriptionEvent($object, $event);
        }

        if (($event['type'] ?? null) === 'invoice.paid') {
            return $this->recordInvoice($object, $event);
        }

        // A refund issued from the Stripe dashboard, or forced by a dispute — money that left the
        // account without going through Billing::refund(). `amount_refunded` on the Charge is
        // Stripe's own running total, which is what makes a re-delivery settle instead of stack.
        // Before the payment_id lookup below: a subscription invoice's charge has none of our
        // metadata and is found by its PaymentIntent instead.
        if (($event['type'] ?? null) === 'charge.refunded') {
            return $this->recordRefundFromWebhook($object, $event);
        }

        $paymentId = $object['metadata']['payment_id'] ?? $object['client_reference_id'] ?? null;

        if ($paymentId === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        $status = match ($event['type'] ?? null) {
            // A subscription checkout whose trial charges nothing completes without a payment.
            'checkout.session.completed' => in_array($object['payment_status'] ?? null, ['paid', 'no_payment_required'], true) ? PaymentStatus::Paid : null,
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

        // A subscription session carries no PaymentIntent; the first invoice's (recordInvoice())
        // may already sit on the row — keep it, refunds go through it.
        if (str_starts_with((string) $payment->external_id, 'pi_') && ! str_starts_with((string) $externalId, 'pi_')) {
            $externalId = $payment->external_id;
        }

        if (! $payment->transitionTo($status, array_filter(['external_id' => $externalId]))) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        // A subscription checkout: link the row now, before PaymentSucceeded reaches the listener,
        // which would otherwise renew the still-unlinked row by our own interval. The paid period
        // follows from the invoice (recordInvoice()).
        if ($status === PaymentStatus::Paid && ! empty($object['subscription'])) {
            $this->syncSubscription((string) $object['subscription']);
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

    /**
     * customer.subscription.* — the event's object is re-fetched rather than trusted: Stripe
     * delivers out of order and never expanded, so the current state is the only safe snapshot.
     * trial_will_end is Stripe's own reminder (hence ReportsTrialEnding) and maps to TrialWillEnd.
     */
    protected function applySubscriptionEvent(array $object, array $event): WebhookResult
    {
        $subscription = $this->findProviderSubscription($object['id'] ?? null)
            ?? $this->findSubscriptionByReference($object['metadata']['subscription_id'] ?? null);

        if ($subscription === null || empty($event['id'])) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        if (($event['type'] ?? null) === 'customer.subscription.trial_will_end') {
            return new WebhookResult(type: WebhookEventType::Subscription, status: 'trial_will_end', subscription: $subscription, externalId: $event['id'], raw: $event);
        }

        $snapshot = $this->subscriptionSnapshot($this->http()->get("/subscriptions/{$object['id']}")->throw()->json());

        if ($snapshot === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        return new WebhookResult(
            type: WebhookEventType::Subscription,
            status: 'synced',
            subscription: $subscription,
            externalId: $event['id'],
            raw: $event,
            snapshot: $snapshot,
        );
    }

    /**
     * invoice.paid for a subscription — the proof a period is paid for (the subscription's own
     * period moves when the invoice is created, before any money). The first invoice belongs to the
     * checkout payment: it only lends that row its PaymentIntent (refunds go through it). Every
     * later one (renewal, plan change) is a new Payment row with the subscription as payable.
     */
    protected function recordInvoice(array $invoice, array $event): WebhookResult
    {
        $ignored = new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        $details = $invoice['parent']['subscription_details'] ?? null;
        $subscriptionId = is_string($details['subscription'] ?? null) ? $details['subscription'] : null;

        if ($subscriptionId === null || empty($invoice['id'])) {
            return $ignored;
        }

        $subscription = $this->findProviderSubscription($subscriptionId)
            ?? $this->findSubscriptionByReference($details['metadata']['subscription_id'] ?? null);

        if ($subscription === null) {
            return $ignored;
        }

        $amount = (int) ($invoice['amount_paid'] ?? 0);
        // A zero invoice (a trial's start) pays for nothing — its period is the trial.
        $periodEnd = $amount > 0 ? collect($invoice['lines']['data'] ?? [])->max(fn (array $line) => $line['period']['end'] ?? 0) : null;
        $paymentIntent = $amount > 0 ? $this->invoicePaymentIntent($invoice['id']) : null;

        if (($invoice['billing_reason'] ?? null) === 'subscription_create') {
            $first = $this->findPaymentByReference($details['metadata']['payment_id'] ?? null);

            if ($first !== null && $paymentIntent !== null && $first->external_id !== $paymentIntent) {
                $first->update(['external_id' => $paymentIntent]);
            }

            $this->syncSubscription($subscriptionId, $periodEnd ?: null);

            return $ignored; // the checkout session reports the first payment itself
        }

        if ($amount <= 0) {
            $this->syncSubscription($subscriptionId, null);

            return $ignored;
        }

        $externalId = $paymentIntent ?? $invoice['id'];
        $payment = Payment::query()->where('gateway', $this->gatewayName)->where('external_id', $externalId)->first()
            ?? Payment::create([
                'status' => PaymentStatus::Pending,
                'type' => PaymentType::Charge,
                'initiation' => PaymentInitiation::Automatic,
                'gateway' => $this->gatewayName,
                'amount' => $amount,
                'currency' => strtoupper((string) ($invoice['currency'] ?? $subscription->price?->currency)),
                'external_id' => $externalId,
                'raw_response' => $invoice,
                'payable_type' => $subscription->getMorphClass(),
                'payable_id' => $subscription->getKey(),
                'billable_type' => $subscription->billable_type,
                'billable_id' => $subscription->billable_id,
                'tenant_id' => $subscription->tenant_id,
            ]);

        $payment->transitionTo(PaymentStatus::Paid);

        $this->syncSubscription($subscriptionId, $periodEnd ?: null);

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: 'succeeded',
            payment: $payment,
            externalId: $invoice['id'],
            raw: $event,
        );
    }

    /** Since 2025-03-31.basil an invoice names its PaymentIntent only inside its payments list. */
    protected function invoicePaymentIntent(string $invoiceId): ?string
    {
        $payments = $this->http()->get("/invoices/{$invoiceId}", ['expand' => ['payments']])->throw()->json('payments.data') ?? [];

        foreach ($payments as $payment) {
            $intent = $payment['payment']['payment_intent'] ?? null;

            if (($payment['status'] ?? null) === 'paid' && is_string($intent)) {
                return $intent;
            }
        }

        return null;
    }

    /**
     * Re-fetches the subscription and applies it. Called from a delivery that already reports a
     * payment, so the snapshot is applied here rather than returned — its events come from what
     * changed, so a re-delivery fires nothing twice.
     */
    protected function syncSubscription(string $subscriptionId, ?int $paidPeriodEnd = null): void
    {
        $entity = $this->http()->get("/subscriptions/{$subscriptionId}")->throw()->json();
        $subscription = $this->findProviderSubscription($subscriptionId)
            ?? $this->findSubscriptionByReference($entity['metadata']['subscription_id'] ?? null);
        $snapshot = $this->subscriptionSnapshot($entity, $paidPeriodEnd);

        if ($subscription !== null && $snapshot !== null) {
            $subscription->applyProviderSnapshot($snapshot);
        }
    }

    /**
     * Stripe's subscription in the package's terms. No occurredAt: every snapshot is re-fetched,
     * i.e. the current state, and Stripe advises against ordering by event time. The paid period
     * only from a paid invoice (see recordInvoice()).
     */
    protected function subscriptionSnapshot(array $entity, ?int $paidPeriodEnd = null, ?string $priceId = null): ?SubscriptionSnapshot
    {
        $status = match ($entity['status'] ?? null) {
            'trialing' => SubscriptionStatus::Trialing,
            'active' => SubscriptionStatus::Active,
            // unpaid: dunning is over but the subscription is kept — no payment is coming.
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'paused' => SubscriptionStatus::Paused,
            'canceled', 'incomplete_expired' => SubscriptionStatus::Canceled,
            'incomplete' => SubscriptionStatus::Incomplete,
            default => null,
        };

        if ($status === null || empty($entity['id'])) {
            return null;
        }

        $date = fn (mixed $timestamp) => is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
        $periodEnd = collect($entity['items']['data'] ?? [])->max(fn (array $item) => $item['current_period_end'] ?? 0);

        return new SubscriptionSnapshot(
            externalId: $entity['id'],
            status: $status,
            currentPeriodEndsAt: $date($paidPeriodEnd),
            // cancel_at, not cancel_at_period_end: in flexible billing mode (the default since
            // 2025-09-30.clover) a cancellation sets only cancel_at.
            cancelsAt: $status === SubscriptionStatus::Canceled
                ? ($date($entity['ended_at'] ?? null) ?? $date($entity['canceled_at'] ?? null) ?? now())
                : ($date($entity['cancel_at'] ?? null) ?? (($entity['cancel_at_period_end'] ?? false) ? $date($periodEnd) : null)),
            trialEndsAt: $status === SubscriptionStatus::Trialing ? $date($entity['trial_end'] ?? null) : null,
            priceId: $priceId,
        );
    }

    /**
     * Checkout turns product_data into a product of its own that is INACTIVE and can't be edited
     * beyond its metadata — and Stripe refuses a new price on an inactive product (both
     * live-found). Such a product is replaced once by an active one of the same name, which every
     * later swap of this subscription then keeps using.
     */
    protected function activeProductFor(string $productId): string
    {
        $product = $this->http()->get("/products/{$productId}")->throw()->json();

        if ($product['active'] ?? false) {
            return $productId;
        }

        return $this->http()->asForm()->post('/products', [
            'name' => $product['name'] ?? 'Subscription',
            'metadata' => ['replaces' => $productId],
        ])->throw()->json('id');
    }

    /**
     * Subscription management calls: a refusal has to reach subscriptionSnapshotOrFail() as a
     * response — http()'s retry would throw it as a bare RequestException first (Laravel throws
     * once retries run out). Sent once: an immediate proration or a cancellation's final invoice
     * moves money, and only the swap carries an idempotency key.
     */
    protected function manage(): PendingRequest
    {
        return $this->http()->retry(1, 0, throw: false);
    }

    protected function subscriptionSnapshotOrFail(\Illuminate\Http\Client\Response $response, ?string $priceId = null): SubscriptionSnapshot
    {
        if ($response->failed()) {
            throw new BillingException(sprintf(
                'Stripe refused the subscription change: %s — %s',
                $response->json('error.code') ?? $response->status(),
                $response->json('error.message') ?? $response->body(),
            ));
        }

        return $this->subscriptionSnapshot($response->json(), priceId: $priceId)
            ?? throw new BillingException('Stripe: unexpected subscription response: ' . $response->body());
    }

    /** An inline recurring price — $product for a new subscription, $productId to stay on an existing one's product. */
    protected function recurringPriceData(Price $price, Money $unit, ?array $product = null, ?string $productId = null): array
    {
        if ($price->pricing_type === PricingType::Metered) {
            throw new NotSupportedException('Stripe: metered prices aren\'t supported on provider-managed subscriptions yet.');
        }

        $interval = match ($price->interval) {
            Interval::Day, Interval::Week, Interval::Month, Interval::Year => $price->interval->value,
            default => throw new NotSupportedException("Stripe: subscriptions bill by day, week, month or year — price {$price->id} has no such interval."),
        };

        return array_filter([
            'currency' => strtolower($unit->currency),
            'unit_amount' => $unit->amount,
            'recurring' => ['interval' => $interval, 'interval_count' => max(1, (int) $price->interval_count)],
            'product_data' => $product,
            'product' => $productId,
        ]);
    }

    protected function recordRefundFromWebhook(array $object, array $event): WebhookResult
    {
        $paymentId = $object['metadata']['payment_id'] ?? null;
        // A subscription invoice's charge carries no metadata of ours — its payment row is keyed
        // by the invoice's PaymentIntent instead.
        $charge = $paymentId !== null
            ? $this->findPaymentByReference($paymentId)
            : (empty($object['payment_intent']) ? null : Payment::query()
                ->where('gateway', $this->gatewayName)
                ->where('type', PaymentType::Charge)
                ->where('external_id', $object['payment_intent'])
                ->first());

        if ($charge === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        // No per-refund id to be had: charge.refunded carries the Charge, and modern API versions
        // don't expand its `refunds` list at all (live-verified — the key is simply absent). The
        // Charge id goes on the row as the reference a support lookup needs, but deduping happens
        // on `amount_refunded`, Stripe's running total: two partial refunds share one Charge id, so
        // deduping on that would silently drop the second.
        $refund = $this->recordExternalRefund(
            $charge,
            isset($object['amount_refunded']) ? (int) $object['amount_refunded'] : null,
            $object['refunds']['data'][0]['id'] ?? null,
            $event,
            reference: isset($object['id']) ? (string) $object['id'] : null,
        );

        if ($refund === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: 'refunded',
            payment: $refund,
            // Never the row's external_id — that is the shared Charge id here, and keying the
            // dedup claim on it would drop the second partial refund's PaymentRefunded.
            externalId: (string) $refund->id, // see AbstractGateway::recordExternalRefund() — the row is the dedup identity
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
                'billable_type' => $billable->getMorphClass(),
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
            $billable->getMorphClass(),
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
            ['name' => 'proration_behavior', 'type' => 'text', 'secret' => false, 'help' => 'Як Stripe рахує зміну тарифу підписки: create_prorations (за замовчуванням), always_invoice, none'],
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
            ->withHeaders(['Stripe-Version' => self::API_VERSION])
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
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('gateway', $this->gatewayName)
            ->whereNotNull('external_customer_id')
            ->value('external_customer_id');

        return $existing ?? $this->createCustomer($billable);
    }
}
