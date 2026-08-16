<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Http\Controllers;

use Fomvasss\Billing\Events\CheckoutReturned;
use Fomvasss\Billing\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The bridge between the gateway's return redirect and the app's own success/failed pages: the
 * drivers point the gateway here by default (see AbstractGateway::successUrl()/failUrl()), this
 * fires CheckoutReturned and forwards the browser to config('billing.return_urls.*') with
 * ?payment={id} appended — so a frontend/SPA page knows which payment to show without the gateway
 * needing to reach the frontend origin directly.
 *
 * Accepts POST as well as GET: WayForPay and Hutko return the customer via an auto-submitted POST
 * form, so this route lives outside the `web` group (no CSRF — a gateway's POST can't carry a
 * token). The redirect out is always 303, turning that POST into a plain GET on the final page.
 */
class CheckoutReturnController extends Controller
{
    public function __invoke(Request $request, Payment $payment, string $outcome): RedirectResponse
    {
        CheckoutReturned::dispatch($payment, $outcome, $request->all());

        $target = config('billing.return_urls.' . ($outcome === 'success' ? 'success' : 'failed'));

        abort_if($target === null, 404);

        $separator = str_contains($target, '?') ? '&' : '?';

        // Incoming query params (ChargeOptions::$returnParams put there by the driver, plus
        // whatever the gateway itself appended) are forwarded to the final page as display hints —
        // 'payment' always wins over a same-named incoming param.
        $params = [...$request->query(), 'payment' => $payment->id];

        return redirect()->to($target . $separator . http_build_query($params), 303);
    }
}
