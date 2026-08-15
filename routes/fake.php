<?php

declare(strict_types=1);

use Fomvasss\Billing\Http\Controllers\FakePaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->get('billing/fake/{payment}', [FakePaymentController::class, 'show'])
    ->name('billing.fake.show');
