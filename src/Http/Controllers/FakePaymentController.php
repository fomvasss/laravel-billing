<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Http\Controllers;

use Fomvasss\Billing\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

class FakePaymentController extends Controller
{
    public function show(Payment $payment): View
    {
        return view('billing::fake.show', ['payment' => $payment]);
    }
}
