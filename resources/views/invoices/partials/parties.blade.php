<table class="parties">
    <tr>
        <td>
            <div class="muted">{{ __('billing::invoice.seller') }}</div>
            @include('billing::invoices.partials.party', ['party' => $document->seller])
        </td>
        <td>
            <div class="muted">{{ __('billing::invoice.buyer') }}</div>
            @include('billing::invoices.partials.party', ['party' => $document->buyer])
        </td>
    </tr>
</table>
