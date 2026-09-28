<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Gateways\Paddle;

use Fomvasss\Billing\Contracts\ChecksGatewayHealth;
use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\Contracts\ManagesProviderSubscriptions;
use Fomvasss\Billing\Contracts\RefundsPayments;
use Fomvasss\Billing\Contracts\StartsProviderSubscriptions;
use Fomvasss\Billing\DTO\ChargeOptions;
use Fomvasss\Billing\DTO\GatewayHealth;
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
use Fomvasss\Billing\Exceptions\BillingException;
use Fomvasss\Billing\Exceptions\NotSupportedException;
use Fomvasss\Billing\Facades\Billing;
use Fomvasss\Billing\Gateways\AbstractGateway;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\Price;
use Fomvasss\Billing\Models\Subscription;
use Fomvasss\Billing\Support\Money;
use Fomvasss\Billing\Webhooks\BillingWebhookCall;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Paddle Billing (not Classic) — api.paddle.com / sandbox-api.paddle.com, verified against
 * developer.paddle.com (api-reference/transactions/create-transaction, build/transactions/
 * default-payment-link, webhooks/about/signature-verification).
 *
 * Paddle is a Merchant of Record, and that shapes what this driver can be:
 *  - No off-session charge outside a Paddle subscription — saved payment methods can only be
 *    listed/deleted, never charged. Hence no TokenizesPaymentMethod.
 *  - No Paddle-hosted checkout page for the web: a transaction's checkout.url is a page on an
 *    APPROVED domain that loads Paddle.js, which opens the checkout by the `_ptxn` query parameter.
 *    That page is the package's own billing.paddle.checkout route (PaddleCheckoutController),
 *    set as the account's default payment link.
 *  - Non-catalog items: the price and product travel inline in the transaction, so nothing has to
 *    be mirrored into Paddle's catalog.
 *
 * Amounts are strings in minor units on both sides — no conversion (unlike LiqPay/WayForPay).
 * custom_data.payment_id is our link back to the row; Paddle echoes it in every transaction event.
 */
class PaddleGateway extends AbstractGateway implements RefundsPayments, ChecksPaymentStatus, ChecksGatewayHealth, StartsProviderSubscriptions, ManagesProviderSubscriptions
{
    protected const LIVE_URL = 'https://api.paddle.com';

    protected const SANDBOX_URL = 'https://sandbox-api.paddle.com';

    public function charge(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        return $this->openTransaction($payment, $options, $this->items($payment, $options), ['payment_id' => (string) $payment->id]);
    }

    /**
     * A checkout whose item recurs: Paddle creates its own subscription once the transaction
     * completes, copies the transaction's custom_data onto it and onto every renewal transaction —
     * which is how the row is found again before Paddle's subscription id is known.
     */
    public function startSubscription(Payment $payment, ChargeOptions $options = new ChargeOptions()): PaymentResult
    {
        /** @var Subscription $subscription */
        $subscription = $payment->payable;
        $price = $subscription->price;
        $qty = max(1, (int) $subscription->qty);

        // A card-required trial completes its checkout transaction at zero; the first payment would
        // then be marked paid for a sum nobody paid. Until that's modelled, no trials.
        if (($price->trial_days ?? 0) > 0) {
            throw new NotSupportedException("Paddle: subscriptions with a trial are not supported yet (price {$price->id}).");
        }

        if ($payment->amount % $qty !== 0) {
            throw new BillingException("Paddle: payment {$payment->id} amount {$payment->amount} doesn't split evenly over quantity {$qty}.");
        }

        return $this->openTransaction($payment, $options, [
            $this->recurringItem($price, $qty, intdiv($payment->amount, $qty), $payment->currency, product: [
                'name' => Str::limit($options->description ?? $price->plan?->name ?? "Subscription {$subscription->id}", 197),
                'tax_category' => $this->credentials['tax_category'] ?? 'standard',
            ]),
        ], ['payment_id' => (string) $payment->id, 'subscription_id' => (string) $subscription->id]);
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd): SubscriptionSnapshot
    {
        return $this->snapshotOrFail($this->manage()->post("/subscriptions/{$subscription->external_id}/cancel", [
            'effective_from' => $atPeriodEnd ? 'next_billing_period' : 'immediately',
        ]));
    }

    /**
     * From the end of the paid period (Paddle's default) — the customer keeps what they paid for,
     * and the subscription reads `paused` only once Paddle actually pauses it.
     */
    public function pause(Subscription $subscription, ?\DateTimeInterface $until): SubscriptionSnapshot
    {
        return $this->snapshotOrFail($this->manage()->post("/subscriptions/{$subscription->external_id}/pause", array_filter([
            'effective_from' => 'next_billing_period',
            'resume_at' => $until !== null ? Carbon::instance($until)->toIso8601ZuluString() : null,
        ])));
    }

    /**
     * A paused subscription resumes now, starting (and billing) a new period; one merely scheduled
     * to pause just loses the scheduled pause.
     */
    public function resume(Subscription $subscription): SubscriptionSnapshot
    {
        $current = $this->manage()->get("/subscriptions/{$subscription->external_id}")->throw()->json('data');

        return $this->snapshotOrFail(match (true) {
            ($current['status'] ?? null) === 'paused' => $this->manage()->post("/subscriptions/{$subscription->external_id}/resume", ['effective_from' => 'immediately']),
            ($current['scheduled_change']['action'] ?? null) === 'pause' => $this->manage()->patch("/subscriptions/{$subscription->external_id}", ['scheduled_change' => null]),
            default => $current,
        });
    }

    /**
     * The price goes to Paddle inline, on the product the subscription already bills — no new
     * product per swap. proration_billing_mode (credential, default prorated_immediately) decides
     * what the customer pays for the change; Paddle refuses a change on a past_due subscription,
     * and allows only do_not_bill on a trialing one.
     */
    public function swapPrice(Subscription $subscription, Price $price): SubscriptionSnapshot
    {
        $current = $this->manage()->get("/subscriptions/{$subscription->external_id}")->throw()->json('data');
        $qty = max(1, (int) $subscription->qty);
        $amount = Billing::resolveChargeAmount($price, $this->gatewayName)->money;

        if (strcasecmp($amount->currency, (string) ($current['currency_code'] ?? '')) !== 0) {
            throw new BillingException("Paddle: subscription {$subscription->id} bills in {$current['currency_code']}, the new price resolves to {$amount->currency}.");
        }

        $productId = $current['items'][0]['price']['product_id'] ?? throw new BillingException("Paddle: subscription {$subscription->external_id} has no item to swap.");

        return $this->snapshotOrFail($this->manage()->patch("/subscriptions/{$subscription->external_id}", [
            'items' => [$this->recurringItem($price, $qty, $amount->amount, $amount->currency, productId: $productId)],
            'proration_billing_mode' => $this->credentials['proration_billing_mode'] ?? 'prorated_immediately',
        ]), $price->id);
    }

    /** @param  array<string, string>  $customData */
    protected function openTransaction(Payment $payment, ChargeOptions $options, array $items, array $customData): PaymentResult
    {
        // A Paddle transaction never expires on its own — a re-issue (billing.pay) would otherwise
        // leave the previous checkout payable next to the new one, and a customer holding both
        // links could pay twice.
        $this->cancelPreviousTransaction($payment);

        $data = $this->http()->post('/transactions', [
            // Gateway-specific extras (discount_id, customer_id, ...). Merged first so the driver's
            // own fields below always win.
            ...$options->raw,
            'items' => $items,
            'currency_code' => $payment->currency,
            'custom_data' => $customData,
            // Opt-in only. Without it Paddle uses the account's default payment link — the
            // billing.paddle.checkout page. An explicit URL (one site of several, staging next to
            // production on one account) is refused unless its domain went through Website approval,
            // even in the sandbox (live-verified), so sending one by default would break every
            // install that hasn't been approved yet.
            ...(empty($this->credentials['checkout_url']) ? [] : ['checkout' => ['url' => $this->credentials['checkout_url']]]),
        ])->throw()->json('data');

        $expiresAt = now()->addMinutes($this->linkTtlMinutes());

        // The checkout page is a plain GET Paddle.js drives — it can't receive ChargeOptions, so
        // the per-charge settings wait for it in the cache (same approach as LiqPay's form).
        Cache::put("billing.paddle_checkout.{$payment->id}", array_filter([
            'success_url' => $this->successUrl($payment, $options),
            'fail_url' => $this->failUrl($payment, $options),
            'locale' => $options->locale,
        ]), $expiresAt);

        return new PaymentResult(
            url: $data['checkout']['url'] ?? throw new BillingException('Paddle: the transaction came back without a checkout URL.'),
            expiresAt: $expiresAt,
            externalId: $data['id'],
            raw: $data,
        );
    }

    public function handleWebhook(BillingWebhookCall $webhookCall): WebhookResult
    {
        $event = $webhookCall->payload;
        $transaction = $event['data'] ?? [];

        if (in_array($event['event_type'] ?? null, ['adjustment.created', 'adjustment.updated'], true)) {
            return $this->applyAdjustment($transaction, $event);
        }

        if (str_starts_with((string) ($event['event_type'] ?? ''), 'subscription.')) {
            return $this->applySubscriptionEvent($transaction, $event);
        }

        $status = match ($event['event_type'] ?? null) {
            // completed, not paid: `paid` arrives before Paddle has finished processing — no fee,
            // no payout totals yet. completed carries everything in one event.
            'transaction.completed' => PaymentStatus::Paid,
            'transaction.canceled' => PaymentStatus::Canceled,
            // transaction.payment_failed is deliberately not here: on a checkout the customer is
            // still on the page and can try another card, so a declined attempt is not an outcome.
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        // A transaction this package didn't create (another integration on the same account) is
        // Ignored, not a failed job.
        $payment = $this->findPaymentByReference($transaction['custom_data']['payment_id'] ?? null);

        // A transaction Paddle generated for a subscription (renewal, plan change, resume): it
        // carries the FIRST payment's custom_data, copied on from the subscription, so without this
        // branch a renewal would land on — and overwrite — the checkout payment.
        if (! empty($transaction['subscription_id']) && ($transaction['id'] ?? null) !== $payment?->external_id) {
            return $status === PaymentStatus::Paid
                ? $this->recordSubscriptionTransaction($transaction, $event)
                : new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        if ($payment === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        $result = $this->applyTransaction($payment, $status, $transaction, $event);

        // The subscription checkout itself: the payment is settled above, the subscription it
        // started gets its first paid period here.
        if ($result->type === WebhookEventType::Payment && $status === PaymentStatus::Paid && ! empty($transaction['subscription_id'])) {
            $this->syncFromTransaction($transaction, $event['occurred_at'] ?? null);
        }

        return $result;
    }

    public function checkStatus(Payment $payment): WebhookResult
    {
        if ($payment->external_id === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored');
        }

        // A refund still awaiting Paddle's approval — the same outcome its adjustment.updated would
        // have delivered, for when that webhook got lost.
        if ($payment->isRefund()) {
            $adjustment = $this->http()->get('/adjustments', ['id' => $payment->external_id])->throw()->json('data.0');

            return is_array($adjustment)
                ? $this->applyAdjustment($adjustment, $adjustment)
                : new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored');
        }

        $transaction = $this->http()->get("/transactions/{$payment->external_id}")->throw()->json('data');

        $status = match ($transaction['status'] ?? null) {
            'completed' => PaymentStatus::Paid,
            'canceled' => PaymentStatus::Canceled,
            // An open checkout nobody paid. Paddle never expires it, so the link's own TTL is the
            // only end it gets — cancel it on Paddle's side first, so it can't be paid afterwards.
            'draft', 'ready' => $payment->payment_url_expires_at?->isPast() ? $this->cancelTransaction($payment->external_id) : null,
            // paid → completed is seconds away; billed/past_due belong to invoices and renewals.
            default => null,
        };

        if ($status === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $transaction);
        }

        $result = $this->applyTransaction($payment, $status, $transaction, $transaction);

        // Same as the webhook path: link the subscription before PaymentSucceeded reaches the
        // listener, which would otherwise renew the still-unlinked row by our own interval.
        if ($result->type === WebhookEventType::Payment && $status === PaymentStatus::Paid && ! empty($transaction['subscription_id'])) {
            $this->syncFromTransaction($transaction, null);
        }

        return $result;
    }

    /**
     * A refund in Paddle is an adjustment, and most of them wait for Paddle's approval — the result
     * says pending then, and the approval arrives as adjustment.updated (see applyAdjustment()).
     * Paddle refunds per transaction line in its own, tax-inclusive terms: our amount is scaled by
     * what the customer actually paid, then spread over the lines still holding refundable money.
     */
    public function refund(Payment $payment, ?Money $amount = null): PaymentResult
    {
        $amount ??= new Money($payment->refundableRemainder(), $payment->currency);

        // Everything, with nothing refunded before: Paddle's own "full" type returns the grand total
        // exactly, with no rounding to go wrong.
        $body = $amount->amount === $payment->amount
            ? ['type' => 'full']
            : ['type' => 'partial', 'items' => $this->refundItems($payment, $amount)];

        // Sent once: no idempotency key, and a timeout says nothing about whether Paddle created it.
        $adjustment = $this->http()->retry(1)->post('/adjustments', [
            ...$body,
            'action' => 'refund',
            'transaction_id' => $payment->external_id,
            'reason' => "Refund of payment {$payment->id}",
        ])->throw()->json('data');

        if (($adjustment['status'] ?? null) === 'rejected') {
            throw new BillingException('Paddle: refund was rejected: ' . json_encode($adjustment));
        }

        return new PaymentResult(
            externalId: $adjustment['id'],
            raw: $adjustment,
            pending: ($adjustment['status'] ?? null) === 'pending_approval',
        );
    }

    /** GET /event-types — needs no permissions and no entities, so it only proves the key works. */
    public function healthCheck(): GatewayHealth
    {
        return $this->probeHealth(function () {
            $this->http()->retry(1)->get('/event-types')->throw();

            return $this->isSandbox() ? 'sandbox' : 'live';
        });
    }

    public static function label(): string
    {
        return 'Paddle';
    }

    /**
     * Events go only to notification destinations registered on Paddle's side — there is no
     * per-transaction callback URL. `billing:paddle-register-webhook` registers one via the API.
     */
    public static function requiresDashboardWebhook(): bool
    {
        return true;
    }

    public static function credentialFields(): array
    {
        return [
            ['name' => 'api_key', 'type' => 'text', 'secret' => true, 'help' => 'API key (pdl_live_apikey_... / pdl_sdbx_apikey_...) — Paddle > Developer tools > Authentication'],
            ['name' => 'client_token', 'type' => 'text', 'secret' => false, 'help' => 'Client-side token (live_... / test_...) для Paddle.js на сторінці оплати'],
            ['name' => 'checkout_url', 'type' => 'text', 'secret' => false, 'help' => 'Необов\'язково: сторінка оплати цього сайту (https://сайт/billing/paddle/checkout, домен має пройти Website approval). Порожньо — default payment link акаунта'],
            ['name' => 'webhook_secret', 'type' => 'text', 'secret' => true, 'help' => 'Secret key notification destination (pdl_ntfset_...)'],
            ['name' => 'tax_category', 'type' => 'text', 'secret' => false, 'help' => 'Податкова категорія inline-продукту: standard, saas, digital-goods, ... (має бути увімкнена в акаунті)'],
            ['name' => 'proration_billing_mode', 'type' => 'text', 'secret' => false, 'help' => 'Як Paddle рахує зміну тарифу підписки: prorated_immediately (за замовчуванням), prorated_next_billing_period, full_immediately, full_next_billing_period, do_not_bill'],
            ['name' => 'link_ttl_minutes', 'type' => 'number', 'secret' => false, 'help' => 'Скільки хвилин живе посилання на оплату (за замовчуванням 1440)'],
        ];
    }

    public static function supportedCurrencies(): array
    {
        // The currency_code list from api-reference/transactions/create-transaction (fetched
        // 2026-09-27), MINUS the zero-decimal ones (JPY, CLP, KRW, VND) — Money and the drivers
        // assume 2-decimal minor units throughout. Minimum amounts per currency apply (UAH 29.00).
        return [
            'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'CHF', 'HKD', 'SGD', 'SEK', 'ARS', 'BRL', 'CNY',
            'COP', 'CZK', 'DKK', 'HUF', 'ILS', 'INR', 'MXN', 'NOK', 'NZD', 'PEN', 'PLN', 'RUB',
            'THB', 'TRY', 'TWD', 'UAH', 'ZAR',
        ];
    }

    /** The sandbox is a separate API host, and a key works on its own host only — the key says which. */
    public function isSandbox(): bool
    {
        return str_starts_with($this->apiKey(), 'pdl_sdbx_');
    }

    protected function applyTransaction(Payment $payment, PaymentStatus $status, array $transaction, array $raw): WebhookResult
    {
        // A canceled transaction that is no longer this payment's checkout — the one charge()
        // canceled itself when it re-issued the link. The row already points at a live successor.
        if ($status === PaymentStatus::Canceled && ($transaction['id'] ?? null) !== $payment->external_id) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $raw);
        }

        if ($status === PaymentStatus::Paid && $this->paidAmountMismatch(
            $payment,
            $this->issuedAmount($transaction),
            $transaction['currency_code'] ?? null,
        )) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $raw);
        }

        if (! $payment->transitionTo($status, array_filter([
            'external_id' => $transaction['id'] ?? null,
            // Paddle's fee in the transaction's currency; null until the transaction is completed.
            ...($status === PaymentStatus::Paid ? $this->feeFrom($transaction['details']['totals']['fee'] ?? null) : []),
        ], fn ($value) => $value !== null))) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $raw);
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: $status === PaymentStatus::Paid ? 'succeeded' : 'canceled',
            payment: $payment,
            externalId: $transaction['id'] ?? (string) $payment->id,
            raw: $raw,
        );
    }

    /**
     * subscription.* — every one carries the whole entity, so each is simply a snapshot. The row
     * is found by Paddle's id once linked, before that by our id in custom_data (copied from the
     * checkout transaction). The paid period is left to the transaction events: Paddle moves
     * current_billing_period forward BEFORE collecting, so reading it here would renew a
     * subscription whose charge may still fail.
     */
    protected function applySubscriptionEvent(array $entity, array $event): WebhookResult
    {
        $subscription = $this->findProviderSubscription($entity['id'] ?? null)
            ?? $this->findSubscriptionByReference($entity['custom_data']['subscription_id'] ?? null);
        $snapshot = $subscription === null ? null : $this->snapshotFrom($entity, $event['occurred_at'] ?? null);

        if ($snapshot === null || empty($event['event_id'])) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        return new WebhookResult(
            type: WebhookEventType::Subscription,
            status: 'synced',
            subscription: $subscription,
            externalId: $event['event_id'],
            raw: $event,
            snapshot: $snapshot,
        );
    }

    /**
     * A renewal (or plan-change, or resume) charge Paddle collected on its own: a new Payment row
     * for it, payable = the subscription, then the subscription's new paid period. Its amount is
     * what the customer paid (grand total) — Paddle computed it, proration included, so there is
     * no issued amount of ours to check it against.
     */
    protected function recordSubscriptionTransaction(array $transaction, array $event): WebhookResult
    {
        $subscription = $this->findProviderSubscription($transaction['subscription_id'])
            ?? $this->findSubscriptionByReference($transaction['custom_data']['subscription_id'] ?? null);

        if ($subscription === null) {
            return new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $event);
        }

        $payment = Payment::query()->where('gateway', $this->gatewayName)->where('external_id', $transaction['id'])->first()
            ?? Payment::create([
                'status' => PaymentStatus::Pending,
                'type' => PaymentType::Charge,
                'initiation' => PaymentInitiation::Automatic,
                'gateway' => $this->gatewayName,
                'amount' => (int) ($transaction['details']['totals']['grand_total'] ?? 0),
                'currency' => $transaction['currency_code'] ?? $subscription->price?->currency,
                'external_id' => $transaction['id'],
                'raw_response' => $transaction,
                'payable_type' => $subscription->getMorphClass(),
                'payable_id' => $subscription->getKey(),
                'billable_type' => $subscription->billable_type,
                'billable_id' => $subscription->billable_id,
                'tenant_id' => $subscription->tenant_id,
            ]);

        $payment->transitionTo(PaymentStatus::Paid, $this->feeFrom($transaction['details']['totals']['fee'] ?? null));

        $this->syncFromTransaction($transaction, $event['occurred_at'] ?? null);

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: 'succeeded',
            payment: $payment,
            externalId: $transaction['id'],
            raw: $event,
        );
    }

    /**
     * A completed subscription transaction is the proof a period is paid for: its billing_period
     * is that period. The rest of the state comes fresh from the API — the transaction doesn't
     * carry it, and a snapshot is the whole state (a missing scheduled cancel would clear one).
     * Applied here rather than returned: this delivery's result already reports the payment, and
     * the snapshot's events are derived from what changed, so a re-delivery fires nothing twice.
     */
    protected function syncFromTransaction(array $transaction, ?string $occurredAt): void
    {
        $subscription = $this->findProviderSubscription($transaction['subscription_id'])
            ?? $this->findSubscriptionByReference($transaction['custom_data']['subscription_id'] ?? null);

        if ($subscription === null) {
            return;
        }

        $entity = $this->http()->get("/subscriptions/{$transaction['subscription_id']}")->throw()->json('data');
        $snapshot = $this->snapshotFrom($entity, $occurredAt, $transaction['billing_period']['ends_at'] ?? null);

        if ($snapshot !== null) {
            $subscription->applyProviderSnapshot($snapshot);
        }
    }

    /**
     * Paddle's subscription entity in the package's terms. $paidPeriodEndsAt only from a completed
     * transaction — see applySubscriptionEvent() for why the entity's own period isn't used.
     */
    protected function snapshotFrom(array $entity, ?string $occurredAt, ?string $paidPeriodEndsAt = null, ?string $priceId = null): ?SubscriptionSnapshot
    {
        $status = match ($entity['status'] ?? null) {
            'active' => SubscriptionStatus::Active,
            'trialing' => SubscriptionStatus::Trialing,
            'past_due' => SubscriptionStatus::PastDue,
            'paused' => SubscriptionStatus::Paused,
            'canceled' => SubscriptionStatus::Canceled,
            default => null,
        };

        if ($status === null || empty($entity['id'])) {
            return null;
        }

        $change = $entity['scheduled_change'] ?? null;
        $date = fn (?string $value) => $value === null ? null : Carbon::parse($value);

        return new SubscriptionSnapshot(
            externalId: $entity['id'],
            status: $status,
            currentPeriodEndsAt: $date($paidPeriodEndsAt),
            cancelsAt: match (true) {
                $status === SubscriptionStatus::Canceled => $date($entity['canceled_at'] ?? null) ?? now(),
                ($change['action'] ?? null) === 'cancel' => $date($change['effective_at'] ?? null),
                default => null,
            },
            trialEndsAt: $status === SubscriptionStatus::Trialing ? $date($entity['current_billing_period']['ends_at'] ?? null) : null,
            pauseEndsAt: $status === SubscriptionStatus::Paused && ($change['action'] ?? null) === 'resume' ? $date($change['effective_at'] ?? null) : null,
            priceId: $priceId,
            occurredAt: $date($occurredAt),
        );
    }

    /**
     * The response to a management call is the subscription entity; its updated_at orders it
     * against the webhooks. A refusal becomes a BillingException carrying Paddle's own code — they
     * are business rules the caller has to show someone (a prorated charge under Paddle's minimum,
     * a change on a past_due subscription, a renewal about to run), not transport failures.
     */
    protected function snapshotOrFail(\Illuminate\Http\Client\Response|array $response, ?string $priceId = null): SubscriptionSnapshot
    {
        if (! is_array($response) && $response->failed()) {
            throw new BillingException(sprintf(
                'Paddle refused the subscription change: %s — %s',
                $response->json('error.code') ?? $response->status(),
                $response->json('error.detail') ?? $response->body(),
            ));
        }

        $entity = is_array($response) ? $response : $response->json('data');

        return $this->snapshotFrom($entity, $entity['updated_at'] ?? null, priceId: $priceId)
            ?? throw new BillingException('Paddle: unexpected subscription response: ' . json_encode($entity));
    }

    /**
     * A recurring non-catalog price, with the quantity pinned like one-off items. $product for a new
     * subscription, $productId to stay on the product a subscription already bills.
     */
    protected function recurringItem(Price $price, int $qty, int $unitAmount, string $currency, ?array $product = null, ?string $productId = null): array
    {
        if ($price->pricing_type === PricingType::Metered) {
            throw new NotSupportedException('Paddle: metered prices can\'t be billed by a Paddle subscription — it charges a fixed amount per period.');
        }

        $interval = match ($price->interval) {
            Interval::Day, Interval::Week, Interval::Month, Interval::Year => $price->interval->value,
            default => throw new NotSupportedException("Paddle: subscriptions bill by day, week, month or year — price {$price->id} has no such interval."),
        };

        return [
            'quantity' => $qty,
            'price' => array_filter([
                'description' => "Price {$price->id}",
                'quantity' => ['minimum' => $qty, 'maximum' => $qty],
                'billing_cycle' => ['interval' => $interval, 'frequency' => max(1, (int) $price->interval_count)],
                'unit_price' => ['amount' => (string) $unitAmount, 'currency_code' => $currency],
                'product' => $product,
                'product_id' => $productId,
            ]),
        ];
    }

    /** Subscription management calls: sent once — resume and an immediate proration charge move money. */
    protected function manage(): PendingRequest
    {
        return $this->http()->retry(1);
    }

    /**
     * adjustment.created/updated — a refund's lifecycle. Ours (Billing::refund()) already has a row,
     * found by the adj_ id: approval completes it, rejection fails it. One issued from the Paddle
     * dashboard is recorded once approved. Only the approval dispatches — a rejected refund returns
     * nothing, and PaymentFailed would be wrong for it: on a subscription renewal's refund the
     * payable is the subscription, and that listener would start dunning.
     */
    protected function applyAdjustment(array $adjustment, array $raw): WebhookResult
    {
        $ignored = new WebhookResult(type: WebhookEventType::Ignored, status: 'ignored', raw: $raw);

        $charge = isset($adjustment['transaction_id'])
            ? Payment::query()
                ->where('gateway', $this->gatewayName)
                ->where('type', PaymentType::Charge)
                ->where('external_id', $adjustment['transaction_id'])
                ->first()
            : null;

        if ($charge === null) {
            return $ignored;
        }

        if (in_array($adjustment['action'] ?? null, ['chargeback', 'chargeback_reverse'], true)) {
            $this->reportUnrecordedReversal($charge, $adjustment);

            return $ignored;
        }

        if (($adjustment['action'] ?? null) !== 'refund' || ! isset($adjustment['id'])) {
            return $ignored;
        }

        $refund = $charge->refunds()->withTrashed()->where('external_id', $adjustment['id'])->first();

        if (($adjustment['status'] ?? null) === 'rejected') {
            if ($refund?->status === PaymentStatus::Pending) {
                $refund->transitionTo(PaymentStatus::Failed);

                Log::warning("Billing [{$this->gatewayName}]: Paddle rejected a refund", [
                    'payment_id' => $charge->id,
                    'refund_id' => $refund->id,
                    'adjustment_id' => $adjustment['id'],
                ]);
            }

            return $ignored;
        }

        if (($adjustment['status'] ?? null) !== 'approved') {
            return $ignored;
        }

        if ($refund?->status === PaymentStatus::Pending) {
            $refund->transitionTo(PaymentStatus::Paid);
        }

        $refund ??= $this->recordExternalReversal($charge, $this->adjustedAmount($charge, $adjustment), $adjustment['id'], $raw);

        if ($refund === null || $refund->status !== PaymentStatus::Paid) {
            return $ignored;
        }

        return new WebhookResult(
            type: WebhookEventType::Payment,
            status: 'refunded',
            payment: $refund,
            // The row, not the adj_ id — Billing::refund() claims the same key for refunds Paddle
            // approved on the spot, so this webhook's echo of one is dropped.
            externalId: (string) $refund->id,
            raw: $raw,
        );
    }

    /** A dashboard refund's size in this payment's terms — Paddle reports it tax-inclusive, as paid. */
    protected function adjustedAmount(Payment $charge, array $adjustment): int
    {
        if (($adjustment['type'] ?? null) === 'full') {
            return $charge->refundableRemainder();
        }

        $transaction = $this->http()->get("/transactions/{$charge->external_id}")->throw()->json('data');
        $paid = (int) ($transaction['details']['totals']['grand_total'] ?? 0);

        return $paid > 0
            ? (int) round((int) ($adjustment['totals']['total'] ?? 0) * $charge->amount / $paid)
            : 0;
    }

    /**
     * $amount in Paddle's terms, spread over the transaction's lines. Paddle refunds tax-inclusive
     * amounts per line, so ours is first scaled by what was actually paid (equal under inclusive
     * tax, larger under exclusive), then each line takes what it still holds — its total minus what
     * earlier refunds, approved or still pending, already claimed from it.
     */
    protected function refundItems(Payment $payment, Money $amount): array
    {
        $transaction = $this->http()->get("/transactions/{$payment->external_id}", ['include' => 'adjustments'])->throw()->json('data');

        $paid = (int) ($transaction['details']['totals']['grand_total'] ?? 0);
        $remaining = $paid > 0 ? (int) round($amount->amount * $paid / $payment->amount) : 0;

        $claimed = [];

        foreach ($transaction['adjustments'] ?? [] as $adjustment) {
            if (($adjustment['action'] ?? null) !== 'refund' || ! in_array($adjustment['status'] ?? null, ['approved', 'pending_approval'], true)) {
                continue;
            }

            foreach ($adjustment['items'] ?? [] as $item) {
                $claimed[$item['item_id']] = ($claimed[$item['item_id']] ?? 0) + (int) ($item['totals']['total'] ?? 0);
            }
        }

        $items = [];

        foreach ($transaction['details']['line_items'] ?? [] as $line) {
            $available = (int) ($line['totals']['total'] ?? 0) - ($claimed[$line['id']] ?? 0);
            $take = min($available, $remaining);

            if ($take > 0) {
                $items[] = ['item_id' => $line['id'], 'type' => 'partial', 'amount' => (string) $take];
                $remaining -= $take;
            }
        }

        if ($items === [] || $remaining > 0) {
            throw new BillingException("Paddle: payment {$payment->id} has no line left to hold a refund of {$amount->amount}.");
        }

        return $items;
    }

    /**
     * What the transaction was issued for — the unit prices we sent, times quantity. Not a totals
     * field: `subtotal` excludes tax, so under an inclusive tax_mode it comes out below the amount
     * we charged, and `total` includes tax, so under an exclusive one it comes out above it. The
     * stale-link case the check exists for (paid after the amount was edited) shows up here all
     * the same, since the old transaction carries the old unit price.
     */
    protected function issuedAmount(array $transaction): ?int
    {
        $items = $transaction['items'] ?? null;

        if (! is_array($items) || $items === []) {
            return null;
        }

        return array_sum(array_map(
            fn (array $item) => (int) ($item['price']['unit_price']['amount'] ?? 0) * (int) ($item['quantity'] ?? 1),
            $items,
        ));
    }

    protected function items(Payment $payment, ChargeOptions $options): array
    {
        $lines = $options->receiptItems !== []
            ? array_map(fn (array $item) => [$item['name'], (int) $item['unitAmount'], (int) $item['qty']], $options->receiptItems)
            : [[$options->description ?? "Payment #{$payment->id}", $payment->amount, 1]];

        return array_map(fn (array $line) => [
            'quantity' => $line[2],
            'price' => [
                // Internal note, never shown to the customer — Paddle requires 2+ characters.
                'description' => "Payment {$payment->id}",
                // Paddle's checkout shows a quantity stepper by default (1–100) — a customer bumping
                // it would pay a sum this payment isn't for, which the amount check then refuses:
                // money taken, row left pending. Pinning the range removes the stepper.
                'quantity' => ['minimum' => $line[2], 'maximum' => $line[2]],
                'unit_price' => [
                    'amount' => (string) $line[1],
                    'currency_code' => $payment->currency,
                ],
                'product' => [
                    'name' => Str::limit($line[0], 197),
                    'tax_category' => $this->credentials['tax_category'] ?? 'standard',
                ],
            ],
        ], $lines);
    }

    /** Best effort: a transaction that can't be canceled any more (already paid) must not block the re-issue. */
    protected function cancelPreviousTransaction(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Paid || ! str_starts_with((string) $payment->external_id, 'txn_')) {
            return;
        }

        try {
            $this->cancelTransaction($payment->external_id);
        } catch (\Throwable $exception) {
            $this->log('cancelPreviousTransaction', ['payment_id' => $payment->id, 'error' => $exception->getMessage()]);
        }
    }

    protected function cancelTransaction(string $transactionId): PaymentStatus
    {
        $this->http()->patch("/transactions/{$transactionId}", ['status' => 'canceled'])->throw();

        return PaymentStatus::Canceled;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->isSandbox() ? self::SANDBOX_URL : self::LIVE_URL)
            ->withToken($this->apiKey())
            ->withHeaders(['Paddle-Version' => '1'])
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 200);
    }

    protected function apiKey(): string
    {
        return $this->credentials['api_key'] ?? throw new BillingException('Paddle: credential "api_key" is missing.');
    }
}
