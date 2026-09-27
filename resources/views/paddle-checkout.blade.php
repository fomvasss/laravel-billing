<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment</title>
    <style>
        body { font-family: sans-serif; max-width: 480px; margin: 120px auto; text-align: center; color: #666; }
    </style>
</head>
<body>
    <p>Opening the payment page…</p>

    <script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
    <script>
        var completed = false;
        var failUrl = @json($failUrl);

        @if ($sandbox)
            Paddle.Environment.set('sandbox');
        @endif

        // No explicit open: Paddle.js opens the checkout for the _ptxn query parameter by itself.
        Paddle.Initialize({
            token: @json($token),
            checkout: {
                settings: @json(array_filter(['displayMode' => 'overlay', 'successUrl' => $successUrl, 'locale' => $locale])),
            },
            eventCallback: function (event) {
                if (event.name === 'checkout.completed') {
                    completed = true;
                }

                // Closing the overlay without paying — send the customer back the way a
                // redirect gateway's cancel button would.
                if (event.name === 'checkout.closed' && ! completed && failUrl) {
                    window.location.href = failUrl;
                }
            },
        });
    </script>
</body>
</html>
