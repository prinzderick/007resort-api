<?php

use App\Domain\Identity\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1 with the `api` middleware group.
Route::prefix('auth')->group(function () {
    Route::post('staff/login', [AuthController::class, 'login'])->middleware('throttle:staff-login');
    Route::post('staff/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-refresh');

    Route::middleware('auth:staff')->group(function () {
        Route::post('staff/logout', [AuthController::class, 'logout']);
        Route::post('staff/step-up', [AuthController::class, 'stepUp'])->middleware('throttle:staff-login');
        Route::post('sessions/{id}/revoke', [AuthController::class, 'revoke']);
    });
});

Route::get('me', [AuthController::class, 'me'])->middleware('auth:staff');
