<table class="totals">
    <tr>
        <td class="right">
            {{ __('billing::invoice.' . ($document->type === 'invoice' && $document->status === 'issued' ? 'total_due' : 'total')) }}: {{ $document->total }}
            @if ($document->conversion && $document->conversion['rate'])
                <div class="muted" style="font-size: 10px; font-weight: normal;">
                    {{ __('billing::invoice.' . ($document->conversion['ratedAt'] ? 'conversion_on' : 'conversion'), [
                        'amount' => $document->conversion['amount'],
                        'rate' => $document->conversion['rate'],
                        'date' => $document->conversion['ratedAt'],
                    ]) }}
                </div>
            @endif
        </td>
    </tr>
</table>
