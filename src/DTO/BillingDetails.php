<?php

declare(strict_types=1);

namespace Fomvasss\Billing\DTO;

/**
 * One side of a document — the seller or the buyer — as it stands at issue time. Stored as a
 * snapshot on the invoice, so a later change to the customer's details never rewrites a document
 * already issued.
 */
final readonly class BillingDetails
{
    public function __construct(
        public string $name,
        /** ЄДРПОУ / ІПН — whatever identifies the party for tax. */
        public ?string $taxId = null,
        public ?string $address = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $iban = null,
        public ?string $bank = null,
        /** Seller only: a path or URL the template can put in the header. */
        public ?string $logo = null,
        /** VAT registration (ІПН платника ПДВ, EU VAT id) — separate from taxId, and only for VAT payers. */
        public ?string $vatId = null,
        /** Seller only: a name printed next to the logo — for a logo that doesn't carry one. */
        public ?string $brand = null,
    ) {}

    public static function fromArray(array $details): self
    {
        return new self(
            name: (string) ($details['name'] ?? ''),
            taxId: $details['tax_id'] ?? null,
            address: $details['address'] ?? null,
            email: $details['email'] ?? null,
            phone: $details['phone'] ?? null,
            iban: $details['iban'] ?? null,
            bank: $details['bank'] ?? null,
            logo: $details['logo'] ?? null,
            vatId: $details['vat_id'] ?? null,
            brand: $details['brand'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'tax_id' => $this->taxId,
            'address' => $this->address,
            'email' => $this->email,
            'phone' => $this->phone,
            'iban' => $this->iban,
            'bank' => $this->bank,
            'logo' => $this->logo,
            'vat_id' => $this->vatId,
            'brand' => $this->brand,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
