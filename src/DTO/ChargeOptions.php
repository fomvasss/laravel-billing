<?php

declare(strict_types=1);

namespace Fomvasss\Billing\DTO;

final readonly class ChargeOptions
{
    public function __construct(
        /** Fiscal basket — filled from Payable::receiptItems() when it implements HasReceiptItems. */
        public array $receiptItems = [],
        public ?string $customerEmail = null,
        public ?string $locale = null,
        public ?string $description = null,
        /** Tokenize the card during this very charge (future recurring charges). */
        public bool $saveCard = false,
        /** Per-charge override; null falls back to config('billing.return_urls.success'). */
        public ?string $successUrl = null,
        public ?string $failUrl = null,
        /** Extra query params on webhookUrl() specifically — a routing hint only, never trusted as-is. */
        public array $webhookUrlParams = [],
        /** Driver-specific: Monobank x_cms/validity, LiqPay rro_info, etc. — read only by the matching driver. */
        public array $raw = [],
    ) {}

    /** Everything else copied as-is — new constructor fields propagate without touching this method. */
    public function withReceiptItems(array $receiptItems): self
    {
        return new self(...['receiptItems' => $receiptItems] + get_object_vars($this));
    }
}
