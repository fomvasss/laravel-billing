@if ($document->paymentMethod)
    <table class="items">
        <tr>
            <th>{{ __('billing::invoice.payment_method') }}</th>
            <th>{{ __('billing::invoice.paid_at') }}</th>
            <th class="right">{{ __('billing::invoice.amount') }}</th>
        </tr>
        <tr>
            <td>{{ $document->paymentMethod }}</td>
            <td>{{ $document->paidAt }}</td>
            <td class="right">{{ $document->total }}</td>
        </tr>
    </table>
@endif
