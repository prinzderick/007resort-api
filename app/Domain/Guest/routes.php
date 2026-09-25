<?php

use App\Domain\Guest\Http\Controllers\GuestContactController;
use App\Domain\Guest\Http\Controllers\GuestOrderController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1. Guest checkout (docs/GUEST_CHECKOUT.md): the website's service token (scope public.checkout) + X-Order-Token / reference + contact.
Route::middleware(['auth:service', 'throttle:customer-api'])->prefix('public/orders')->group(function () {
    Route::post('lookup', [GuestOrderController::class, 'lookup']);
    Route::get('{reference}', [GuestOrderController::class, 'show']);
    Route::post('{reference}/resend', [GuestOrderController::class, 'resend'])->middleware('idempotent');
    Route::post('{reference}/create-account', [GuestOrderController::class, 'createAccount']);
});

Route::middleware(['auth:staff', 'device:optional', 'permission:config.manage'])->group(function () {
    Route::post('guest-contacts/erasure', [GuestContactController::class, 'erase'])->middleware('idempotent');
});
