<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Http\Controllers;

use Fomvasss\Billing\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * The redirect target `BillingManager::charge()` writes into `payment_url` for a form-only gateway
 * (currently just LiqPay) instead of leaving it null — see "PaymentResult — url чи form" in the
 * package plan. Renders whatever `PaymentResult::$form` the driver returned, cached by
 * storeCheckoutForm(), as a self-submitting HTML form.
 */
class CheckoutFormController extends Controller
{
    public function show(Payment $payment): View
    {
        $form = Cache::get("billing.checkout_form.{$payment->id}");

        abort_if($form === null, 404, 'This checkout link has expired — charge the payment again to get a new one.');

        return view('billing::checkout-form', ['form' => $form]);
    }
}
