{{-- An unpaid invoice: the pay link as a button, and what to write in a transfer to the seller's IBAN. --}}
@if ($document->type === 'invoice' && $document->status === 'issued' && ($document->payUrl || $document->seller->iban))
    <table class="pay">
        <tr>
            @if ($document->payUrl)
                <td>
                    <div class="muted">{{ __('billing::invoice.pay_online') }}</div>
                    <a class="button" href="{{ $document->payUrl }}">{{ __('billing::invoice.pay_button', ['total' => $document->total]) }}</a>
                </td>
            @endif
            @if ($document->seller->iban)
                <td>
                    <div class="muted">{{ __('billing::invoice.purpose') }}</div>
                    {{ __('billing::invoice.purpose_text', ['number' => $document->number]) }}
                </td>
            @endif
        </tr>
    </table>
@endif
