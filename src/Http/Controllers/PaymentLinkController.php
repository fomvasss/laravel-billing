<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Http\Controllers;

use Fomvasss\Billing\BillingManager;
use Fomvasss\Billing\Events\PaymentLinkOpened;
use Fomvasss\Billing\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The permanent pay link (billing.pay) — the URL safe to put in an email/invoice, because it
 * never goes stale: a pending payment with a live checkout link redirects straight to it; an
 * expired/failed/canceled one gets a FRESH checkout via charge() first (a new gateway-side
 * invoice — the old one is simply left to expire on the gateway's side); an already-paid one
 * lands on the success page. Fires PaymentLinkOpened on every visit.
 *
 * Re-issuing uses default ChargeOptions — per-charge extras from the original call (saveCard,
 * description, raw) are not remembered; receiptItems still auto-fill from a HasReceiptItems
 * payable. Note: a gateway may refuse a re-issue for a reference it considers final — that
 * surfaces as the driver's own exception, not silently.
 */
class PaymentLinkController extends Controller
{
    public function __invoke(Request $request, Payment $payment): RedirectResponse
    {
        abort_if($payment->isRefund(), 404);

        PaymentLinkOpened::dispatch($payment);

        if ($payment->isPaid()) {
            $target = config('billing.return_urls.success');

            abort_if($target === null, 404);

            $separator = str_contains($target, '?') ? '&' : '?';

            return redirect()->away($target . $separator . http_build_query(['payment' => $payment->id]), 303);
        }

        // Reuse the existing checkout only while it's genuinely usable — pending AND unexpired.
        // failed/canceled always get a fresh one: their old gateway page is a dead end.
        if (! $payment->isPending() || ! $payment->hasActivePaymentUrl()) {
            app(BillingManager::class)->charge($payment);
            $payment->refresh();
        }

        return redirect()->away($payment->payment_url, 303);
    }
}
