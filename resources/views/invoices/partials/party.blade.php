<strong>{{ $party->name }}</strong><br>
@if ($party->taxId){{ __('billing::invoice.tax_id') }}: {{ $party->taxId }}<br>@endif
@if ($party->vatId){{ __('billing::invoice.vat_id') }}: {{ $party->vatId }}<br>@endif
{{-- the address may span lines (street / city / postcode / country) --}}
@if ($party->address){!! nl2br(e($party->address)) !!}<br>@endif
@if ($party->iban){{ __('billing::invoice.iban') }}: {{ $party->iban }}<br>@endif
@if ($party->bank){{ __('billing::invoice.bank') }}: {{ $party->bank }}<br>@endif
@if ($party->email){{ $party->email }}<br>@endif
@if ($party->phone){{ $party->phone }}@endif
