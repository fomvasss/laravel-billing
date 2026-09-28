@if ($document->payUrl)
    <div class="pay">
        {{ __('billing::invoice.pay_online') }}: <a href="{{ $document->payUrl }}">{{ $document->payUrl }}</a>
    </div>
@endif
