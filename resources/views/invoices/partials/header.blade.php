<table>
    <tr>
        <td>
            @if ($document->seller->logo && $document->seller->brand)
                <table style="width: auto; margin-bottom: 8px;">
                    <tr>
                        <td style="padding: 0 10px 0 0; vertical-align: middle;"><img src="{{ $document->seller->logo }}" alt="" style="max-height: 48px;"></td>
                        <td class="brand" style="padding: 0; vertical-align: middle;">{{ $document->seller->brand }}</td>
                    </tr>
                </table>
            @elseif ($document->seller->logo)
                <img src="{{ $document->seller->logo }}" alt="" style="max-height: 48px; margin-bottom: 8px;"><br>
            @elseif ($document->seller->brand)
                <div class="brand" style="margin-bottom: 8px;">{{ $document->seller->brand }}</div>
            @endif
            <h1>{{ __('billing::invoice.' . $document->type) }} {{ __('billing::invoice.number', ['number' => $document->number]) }}</h1>
            @if ($document->invoiceNumber)
                <div class="muted">{{ __('billing::invoice.for_invoice', ['number' => $document->invoiceNumber]) }}</div>
            @endif
        </td>
        <td class="right">
            <div>{{ __('billing::invoice.issued_at') }}: {{ $document->issuedAt }}</div>
            @if ($document->dueAt && $document->status === 'issued')
                <div>{{ __('billing::invoice.due_at') }}: {{ $document->dueAt }}</div>
            @endif
            @if ($document->paidAt)
                <div>{{ __('billing::invoice.paid_at') }}: {{ $document->paidAt }}</div>
            @endif
            @if ($document->status === 'paid')
                <div class="stamp" style="margin-top: 6px;">{{ __('billing::invoice.status_paid') }}</div>
            @elseif ($document->status === 'void')
                <div class="stamp void" style="margin-top: 6px;">{{ __('billing::invoice.status_void') }}</div>
            @endif
        </td>
    </tr>
</table>
