<?php

use App\Domain\Customer\Http\Controllers\CustomerAuthController;
use App\Domain\Customer\Http\Controllers\CustomerMeController;
use App\Domain\Customer\Http\Controllers\PublicController;
use App\Domain\Customer\Http\Controllers\ServiceTokenController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1. Customer identity is a separate guard (`customer`, bearer r7c_...): it can never satisfy `auth:staff`.

Route::get('public/site', [PublicController::class, 'site'])->middleware('throttle:public-site');
Route::post('public/ticket-orders', [PublicController::class, 'ticketOrder'])->middleware(['auth:customer,service', 'throttle:customer-api', 'permission.public:ticket.order', 'idempotent']);

Route::prefix('customer/auth')->group(function () {
    Route::post('register', [CustomerAuthController::class, 'register'])->middleware('throttle:customer-register');
    Route::post('verify', [CustomerAuthController::class, 'verify'])->middleware('throttle:customer-verify');
    Route::post('verify/resend', [CustomerAuthController::class, 'resend'])->middleware('throttle:customer-verify');
    Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:customer-login');
    Route::post('refresh', [CustomerAuthController::class, 'refresh'])->middleware('throttle:customer-refresh');
    Route::post('forgot', [CustomerAuthController::class, 'forgot'])->middleware('throttle:customer-forgot');
    Route::post('reset', [CustomerAuthController::class, 'reset'])->middleware('throttle:customer-forgot');
    Route::post('logout', [CustomerAuthController::class, 'logout'])->middleware('auth:customer');
});

Route::middleware(['auth:customer', 'throttle:customer-api'])->prefix('customer')->group(function () {
    Route::get('me', [CustomerMeController::class, 'me']);
    Route::get('bookings', [CustomerMeController::class, 'bookings']);
    Route::get('entitlements', [CustomerMeController::class, 'entitlements']);
    Route::get('memberships', [CustomerMeController::class, 'memberships']);
    Route::get('orders/{orderId}', [CustomerMeController::class, 'order']);
});

Route::middleware(['auth:staff', 'device:optional', 'permission:config.manage'])->prefix('service-tokens')->group(function () {
    Route::get('/', [ServiceTokenController::class, 'index']);
    Route::post('/', [ServiceTokenController::class, 'store'])->middleware('idempotent');
    Route::post('{id}/rotate', [ServiceTokenController::class, 'rotate'])->middleware('idempotent');
    Route::post('{id}/revoke', [ServiceTokenController::class, 'revoke'])->middleware('idempotent');
});
