<?php

use App\Domain\Devices\Http\Controllers\BroadcastAuthController;
use App\Domain\Devices\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

Route::prefix('devices')->group(function () {
    // Public: authenticated by the one-time registration code only. Deliberately NOT `idempotent`-gated: the response carries the
    // device secret, which must never be stored in idempotency_record; the single-use code makes a repeat a clean 422 instead.
    Route::post('register', [DeviceController::class, 'register'])->middleware('throttle:device-register');

    // Device self-service: device token of that device, or staff bearer (admin).
    Route::middleware('auth.any')->group(function () {
        Route::get('{deviceId}', [DeviceController::class, 'show']);
        Route::post('{deviceId}/status', [DeviceController::class, 'status']);
        Route::get('{deviceId}/commands', [DeviceController::class, 'commands']);
    });

    Route::middleware(['auth:staff', 'device:optional'])->group(function () {
        Route::get('/', [DeviceController::class, 'index'])->middleware('permission:device.register|device.view|device.manage');
        Route::post('registration-codes', [DeviceController::class, 'issueCode'])->middleware('permission:device.register');
        Route::post('{deviceId}/checkout', [DeviceController::class, 'checkout'])->middleware('idempotent');
        Route::post('{deviceId}/checkin', [DeviceController::class, 'checkin'])->middleware('idempotent');
        Route::post('{deviceId}/revoke', [DeviceController::class, 'revoke'])->middleware(['permission:device.revoke', 'idempotent']);
    });
});

// Reverb/Pusher private-channel authorization (bearer + X-Device-Token). Rules: App\Support\Realtime\Channels.
Route::post('broadcasting/auth', [BroadcastAuthController::class, 'auth'])
    ->middleware(['auth:staff', 'device:optional']);
