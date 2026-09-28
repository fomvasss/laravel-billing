{{-- extra['note'] — a free-text line passed at issue time (a contract reference, payment terms). --}}
@if (is_string($document->extra['note'] ?? null) && $document->extra['note'] !== '')
    <div class="pay">{{ $document->extra['note'] }}</div>
@endif
