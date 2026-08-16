<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Fake payment #{{ $payment->id }}</title>
    <style>
        body { font-family: sans-serif; max-width: 480px; margin: 80px auto; text-align: center; }
        p { color: #666; }
        form { display: inline-block; margin: 0 8px; }
        button { padding: 10px 24px; font-size: 16px; cursor: pointer; }
        .paid { background: #2e7d32; color: #fff; border: 0; }
        .rejected { background: #c62828; color: #fff; border: 0; }
    </style>
</head>
<body>
    <h1>Fake gateway</h1>
    <p>Payment #{{ $payment->id }} — {{ $payment->amount }} {{ $payment->currency }} (local/testing only)</p>

    {{-- No @csrf — this posts straight to the webhook endpoint, which deliberately has no CSRF
         protection (real bank webhooks can't carry a Laravel session token either). --}}
    <form method="post" action="{{ route('billing.webhook', ['gateway' => 'fake']) }}">
        <input type="hidden" name="payment_id" value="{{ $payment->id }}">
        <input type="hidden" name="result" value="success">
        <button type="submit" class="paid">Paid</button>
    </form>

    <form method="post" action="{{ route('billing.webhook', ['gateway' => 'fake']) }}">
        <input type="hidden" name="payment_id" value="{{ $payment->id }}">
        <input type="hidden" name="result" value="failure">
        <button type="submit" class="rejected">Rejected</button>
    </form>
</body>
</html>
