<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Http\Controllers;

use Fomvasss\Billing\Contracts\CredentialResolverContract;
use Fomvasss\Billing\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * The account's default payment link — every transaction's checkout.url is this page plus
 * `?_ptxn=txn_...`. Paddle.js opens the checkout for that parameter by itself, so all this has to
 * do is load and initialize it, with the return URLs of the payment the transaction belongs to.
 * Paddle also sends customers here to update a subscription's card: a transaction that isn't one
 * of our payments just gets the checkout, without return URLs.
 */
class PaddleCheckoutController extends Controller
{
    public function show(Request $request): View
    {
        $transactionId = $request->query('_ptxn');
        $payment = is_string($transactionId) && $transactionId !== ''
            ? Payment::query()->where('external_id', $transactionId)->first()
            : null;

        // The gateway name the payment went through — a second Paddle account registered under
        // another name points its default payment link at this same page, with its own token.
        $credentials = app(CredentialResolverContract::class)->resolve($payment->gateway ?? 'paddle', $payment?->billable?->tenantId());
        $token = $credentials['client_token'] ?? null;

        abort_if(! is_string($token) || $token === '', 404, 'Paddle checkout is not configured.');

        $settings = $payment === null ? [] : Cache::get("billing.paddle_checkout.{$payment->id}", []);

        return view('billing::paddle-checkout', [
            'token' => $token,
            'sandbox' => str_starts_with($token, 'test_'),
            'successUrl' => $settings['success_url'] ?? null,
            'failUrl' => $settings['fail_url'] ?? null,
            'locale' => $settings['locale'] ?? null,
        ]);
    }
}
