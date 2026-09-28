{{-- The line a reader looks for first: how much, and by when / since when. --}}
@if ($document->status !== 'void')
    <div class="summary">
        @if ($document->type === 'receipt')
            {{ __('billing::invoice.summary_paid', ['total' => $document->total, 'date' => $document->paidAt]) }}
        @elseif ($document->status === 'paid')
            {{ __('billing::invoice.summary_paid', ['total' => $document->total, 'date' => $document->paidAt]) }}
        @elseif ($document->dueAt)
            {{ __('billing::invoice.summary_due', ['total' => $document->total, 'date' => $document->dueAt]) }}
        @else
            {{ __('billing::invoice.summary_due_now', ['total' => $document->total]) }}
        @endif
    </div>
@endif
