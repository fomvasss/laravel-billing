<?php

declare(strict_types=1);

namespace Fomvasss\Billing\DTO;

use Fomvasss\Billing\Enums\PaymentInitiation;

final readonly class ChargeOptions
{
    public function __construct(
        /** Fiscal basket — filled from Payable::receiptItems() when it implements HasReceiptItems. */
        public array $receiptItems = [],
        public ?string $customerEmail = null,
        /**
         * The customer's IP. Required by LiqPay for an off-session `paytoken` charge and used by
         * Hutko as `client_ip`; there's no live request behind a scheduled renewal, so pass the
         * last address you saw. Drivers that don't take one ignore it.
         */
        public ?string $customerIp = null,
        public ?string $locale = null,
        public ?string $description = null,
        /** Tokenize the card during this very charge (future recurring charges). */
        public bool $saveCard = false,
        /** Per-charge override; null falls back to config('billing.return_urls.success'). */
        public ?string $successUrl = null,
        public ?string $failUrl = null,
        /** Extra query params on webhookUrl() specifically — a routing hint only, never trusted as-is. */
        public array $webhookUrlParams = [],
        /** Extra query params forwarded onto the final return_urls.* redirect (e.g. ['order' => 1042]) — display hints for the frontend page, never trusted as-is. */
        public array $returnParams = [],
        /**
         * Overrides who gets recorded as having started the charge. Left null, charge() records
         * Manual and chargeWithMethod() Automatic — right for the usual pair (a checkout a person
         * opened, a renewal nobody was present for). Set it when the mechanism and the initiator
         * disagree: a one-click "pay with the saved card" button is chargeWithMethod() but Manual,
         * and a retry your own scheduler fires through charge() is Automatic.
         */
        public ?PaymentInitiation $initiation = null,
        /** Driver-specific: Monobank x_cms/validity, LiqPay rro_info, etc. — read only by the matching driver. */
        public array $raw = [],
    ) {}

    /** Everything else copied as-is — new constructor fields propagate without touching this method. */
    public function withReceiptItems(array $receiptItems): self
    {
        return new self(...['receiptItems' => $receiptItems] + get_object_vars($this));
    }
}
