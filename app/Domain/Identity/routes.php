<?php

use App\Domain\Identity\Http\Controllers\AuthController;
use App\Domain\Identity\Http\Controllers\RoleAssignmentController;
use App\Domain\Identity\Http\Controllers\RoleController;
use App\Domain\Identity\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1 with the `api` middleware group. Contract: 007resort-docs api/openapi/v1.yaml.
Route::prefix('auth')->group(function () {
    Route::post('staff/login', [AuthController::class, 'login'])->middleware(['throttle:staff-login', 'device:optional']); // X-Device-Token binds the session to the device
    Route::post('staff/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-refresh');

    Route::middleware('auth:staff')->group(function () {
        Route::post('staff/logout', [AuthController::class, 'logout']);
        Route::post('staff/step-up', [AuthController::class, 'stepUp'])->middleware('throttle:staff-login');
        Route::post('sessions/{id}/revoke', [AuthController::class, 'revoke'])->middleware('idempotent');
        Route::get('me', [AuthController::class, 'me'])->middleware('device:optional');
    });
});

Route::get('me', [AuthController::class, 'me'])->middleware(['auth:staff', 'device:optional']); // alias of /auth/me

// Identity administration. Secrets (PIN/password) are write-only; credential endpoints are deliberately NOT idempotency-key
// gated (the request hash of a 4-digit PIN would be brute-forceable from the idempotency table) — PUT is naturally idempotent.
Route::middleware('auth:staff')->group(function () {
    Route::middleware('permission:staff.manage')->group(function () {
        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff', [StaffController::class, 'store'])->middleware('idempotent');
        Route::get('staff/{staffId}', [StaffController::class, 'show']);
        Route::patch('staff/{staffId}', [StaffController::class, 'update']);
        Route::put('staff/{staffId}/credentials/password', [StaffController::class, 'setPassword']);
        Route::put('staff/{staffId}/credentials/pin', [StaffController::class, 'setPin']);
        Route::put('staff/{staffId}/credentials/nfc-card', [StaffController::class, 'registerCard']);
        Route::delete('staff/{staffId}/credentials/nfc-card', [StaffController::class, 'removeCard']);
    });

    Route::middleware('permission:role_assignment.manage')->group(function () {
        Route::get('roles', [RoleController::class, 'roles']);
        Route::get('permissions', [RoleController::class, 'permissions']);
        Route::get('staff/{staffId}/role-assignments', [RoleAssignmentController::class, 'index']);
        Route::post('staff/{staffId}/role-assignments', [RoleAssignmentController::class, 'store'])->middleware('idempotent');
        Route::delete('staff/{staffId}/role-assignments/{assignmentId}', [RoleAssignmentController::class, 'destroy'])->middleware('idempotent');
    });
});
