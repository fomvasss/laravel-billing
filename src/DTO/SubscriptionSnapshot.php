<?php

declare(strict_types=1);

namespace Fomvasss\Billing\DTO;

use Fomvasss\Billing\Enums\SubscriptionStatus;

/**
 * A provider-managed subscription's state as the provider reports it, already translated into the
 * package's terms by the driver — Subscription::applyProviderSnapshot() writes it and derives the
 * events from what changed. Built from a webhook, or from the provider's response to a forwarded
 * cancel/pause/resume/swap.
 *
 * Fields describe the whole state, not a delta: a null cancelsAt/pauseEndsAt means "none
 * scheduled" and clears the column. The exceptions are currentPeriodEndsAt, trialEndsAt and
 * priceId, where null means "not reported, keep what's there".
 */
final readonly class SubscriptionSnapshot
{
    public function __construct(
        /** The provider's own subscription id — becomes subscriptions.external_id. */
        public string $externalId,
        public SubscriptionStatus $status,
        /**
         * The end of the period that is PAID for. A provider that moves its period forward before
         * collecting (Paddle does) should report the new end only once the renewal is paid —
         * moving it is what fires SubscriptionRenewed.
         */
        public ?\DateTimeInterface $currentPeriodEndsAt = null,
        /** When access ends because of a cancellation — scheduled (future) or done (past). */
        public ?\DateTimeInterface $cancelsAt = null,
        public ?\DateTimeInterface $trialEndsAt = null,
        /** A scheduled resume of a paused subscription. */
        public ?\DateTimeInterface $pauseEndsAt = null,
        /** Our Price the provider now bills, when it changed and the driver could map it back. */
        public ?string $priceId = null,
        /**
         * When the provider recorded this state (Paddle's occurred_at, Stripe's re-fetched object).
         * Deliveries aren't ordered: a snapshot older than the last one applied is dropped. Null
         * applies unconditionally.
         */
        public ?\DateTimeInterface $occurredAt = null,
    ) {}
}
