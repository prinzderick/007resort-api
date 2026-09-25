<?php

use App\Domain\Customer\Http\Controllers\CustomerAuthController;
use App\Domain\Customer\Http\Controllers\CustomerMeController;
use App\Domain\Customer\Http\Controllers\PublicController;
use App\Domain\Customer\Http\Controllers\ServiceTokenController;
use App\Domain\Customer\Http\Controllers\SocialController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1. Customer identity is a separate guard (`customer`, bearer r7c_...): it can never satisfy `auth:staff`.

Route::get('public/site', [PublicController::class, 'site'])->middleware('throttle:public-site');
Route::post('public/ticket-orders', [PublicController::class, 'ticketOrder'])->middleware(['auth:customer,service', 'throttle:customer-api', 'permission.public:ticket.order', 'idempotent']);

// Social sign-in (docs/CUSTOMER_SOCIAL_LOGIN.md). `service_scope` default = the guard does not force public.read; service.scope enforces customer.social (403).
Route::prefix('public/customers/social')->group(function () {
    Route::get('providers', [SocialController::class, 'providers'])->defaults('service_scope', 'any')->middleware(['auth:service', 'throttle:customer-api']);
    Route::post('login', [SocialController::class, 'login'])->defaults('service_scope', 'customer.social')->middleware(['auth:service', 'service.scope:customer.social', 'throttle:social-login']);
    Route::post('link/confirm', [SocialController::class, 'confirm'])->defaults('service_scope', 'customer.social')->middleware(['auth:service', 'service.scope:customer.social', 'throttle:social-confirm']);
});
Route::post('customer/auth/social/token', [SocialController::class, 'tokenLogin'])->middleware('throttle:social-public');
Route::post('customer/me/social/link', [SocialController::class, 'link'])->middleware(['social.link.auth', 'throttle:customer-api', 'idempotent']);

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
    Route::patch('me', [SocialController::class, 'updateMe']);
    Route::get('me/identities', [SocialController::class, 'identities']);
    Route::delete('me/identities/{id}', [SocialController::class, 'unlink']);
    Route::middleware('throttle:customer-credential')->group(function () {
        Route::post('me/password', [SocialController::class, 'setPassword']);
        Route::post('me/email', [SocialController::class, 'startEmail']);
        Route::post('me/email/verify', [SocialController::class, 'verifyEmail']);
    });
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
