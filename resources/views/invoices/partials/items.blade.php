<table class="items">
    <tr>
        <th>{{ __('billing::invoice.item') }}</th>
        <th class="right">{{ __('billing::invoice.qty') }}</th>
        <th class="right">{{ __('billing::invoice.price') }}</th>
        <th class="right">{{ __('billing::invoice.amount') }}</th>
    </tr>
    @foreach ($document->items as $item)
        <tr>
            <td>
                {{ $item['name'] }}@if ($item['sku']) <span class="muted">({{ $item['sku'] }})</span>@endif
                @if ($item['period'])<br><span class="muted">{{ $item['period'] }}</span>@endif
            </td>
            <td class="right">{{ $item['qty'] }}</td>
            <td class="right">{{ $item['unitPrice'] }}</td>
            <td class="right">{{ $item['total'] }}</td>
        </tr>
    @endforeach
</table>
