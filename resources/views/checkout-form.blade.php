<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Redirecting to payment…</title>
    <style>
        body { font-family: sans-serif; max-width: 480px; margin: 120px auto; text-align: center; color: #666; }
        button { padding: 10px 24px; font-size: 16px; cursor: pointer; margin-top: 16px; }
    </style>
</head>
<body>
    <p>Redirecting to the payment page…</p>

    <form id="billing-checkout-form" method="post" action="{{ $form['action'] }}">
        @foreach ($form['fields'] as $name => $value)
            @if (is_array($value))
                @foreach ($value as $item)
                    <input type="hidden" name="{{ $name }}[]" value="{{ $item }}">
                @endforeach
            @else
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach

        {{-- Fallback for JS-disabled browsers — the auto-submit below is the normal path. --}}
        <noscript><button type="submit">Continue to payment</button></noscript>
    </form>

    <script>document.getElementById('billing-checkout-form').submit();</script>
</body>
</html>
