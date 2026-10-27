<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Http\Controllers;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Contracts\ChecksPaymentStatus;
use Fomvasss\Billing\Enums\PaymentStatus;
use Fomvasss\Billing\Events\CheckoutReturned;
use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Support\WebhookResultDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The bridge between the gateway's return redirect and the app's own success/failed pages: the
 * drivers point the gateway here by default (see AbstractGateway::successUrl()/failUrl()), this
 * fires CheckoutReturned and forwards the browser to config('billing.return_urls.*') with
 * ?payment={id}&status={status} appended — so a frontend/SPA page knows which payment to show
 * without the gateway needing to reach the frontend origin directly.
 *
 * The page is picked by the payment's own status, not by the {outcome} in the URL: Monobank has a
 * single redirectUrl, so a declined card came back through "success" too. A row the webhook has
 * not settled yet is polled once (ChecksPaymentStatus) before deciding; still pending after that —
 * the URL's outcome stands, and `status=pending` tells the page not to claim the money arrived.
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

        $status = $this->settle($payment);

        $page = match ($status) {
            PaymentStatus::Paid => 'success',
            PaymentStatus::Failed, PaymentStatus::Canceled => 'failed',
            PaymentStatus::Pending => $outcome === 'success' ? 'success' : 'failed',
        };

        $target = config('billing.return_urls.' . $page);

        abort_if($target === null, 404);

        $separator = str_contains($target, '?') ? '&' : '?';

        // Incoming query params (ChargeOptions::$returnParams put there by the driver, plus
        // whatever the gateway itself appended) are forwarded to the final page as display hints —
        // 'payment' and 'status' always win over same-named incoming params.
        $params = [...$request->query(), 'payment' => $payment->id, 'status' => $status->value];

        return redirect()->to($target . $separator . http_build_query($params), 303);
    }

    /**
     * The customer often beats the webhook back here by a second or two. One poll through the same
     * dedup the reconcile command uses: the outcome it finds fires the exact events a webhook
     * would, and never twice when the webhook lands meanwhile. A poll that fails leaves the row
     * pending — the webhook or the reconcile command settles it later, the page only shows less.
     */
    protected function settle(Payment $payment): PaymentStatus
    {
        if ($payment->status !== PaymentStatus::Pending || $payment->gateway === null || $payment->external_id === null) {
            return $payment->status;
        }

        try {
            $driver = app(BillingManager::class)->driver($payment->gateway, $payment->billable?->tenantId());

            if ($driver instanceof ChecksPaymentStatus) {
                WebhookResultDispatcher::dispatchOnce($payment->gateway, $driver->checkStatus($payment), 'return');
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $payment->refresh()->status;
    }
}
