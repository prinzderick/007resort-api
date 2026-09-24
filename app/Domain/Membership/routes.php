<?php

use App\Domain\Membership\Http\Controllers\MembershipController;
use App\Domain\Membership\Http\Controllers\PlanController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1 with the `api` middleware group.
Route::middleware(['auth:staff,customer,service', 'device:optional', 'throttle:customer-api'])->prefix('memberships')->group(function () {
    Route::get('plans', [PlanController::class, 'index']);
    Route::post('/', [MembershipController::class, 'purchase'])->middleware(['permission.public:membership.sell', 'idempotent']);
    Route::get('{membership}', [MembershipController::class, 'show'])->whereUuid('membership')->middleware('permission.public:membership.view');
});

Route::middleware(['auth:staff', 'device:optional'])->prefix('memberships')->group(function () {
    Route::post('plans', [PlanController::class, 'store'])->middleware(['permission:membership.plan.manage', 'idempotent']);
    Route::patch('plans/{plan}', [PlanController::class, 'update'])->whereUuid('plan')->middleware(['permission:membership.plan.manage', 'idempotent']);

    Route::post('validate', [MembershipController::class, 'validateMembership'])->middleware('permission:membership.validate,facility=facilityId');

    Route::get('/', [MembershipController::class, 'index'])->middleware('permission:membership.view');

    Route::middleware('permission:membership.view')->group(function () {
        Route::get('{membership}/history', [MembershipController::class, 'history'])->whereUuid('membership');
        Route::get('{membership}/usage', [MembershipController::class, 'usage'])->whereUuid('membership');
    });

    Route::post('{membership}/renew', [MembershipController::class, 'renew'])->whereUuid('membership')->middleware(['permission:membership.sell', 'idempotent']);
    Route::middleware(['permission:membership.manage', 'idempotent'])->group(function () {
        Route::post('{membership}/suspend', [MembershipController::class, 'suspend'])->whereUuid('membership');
        Route::post('{membership}/reinstate', [MembershipController::class, 'reinstate'])->whereUuid('membership');
        Route::post('{membership}/cancel', [MembershipController::class, 'cancel'])->whereUuid('membership');
        Route::post('{membership}/cards', [MembershipController::class, 'attachCard'])->whereUuid('membership');
        Route::post('{membership}/cards/{card}/revoke', [MembershipController::class, 'revokeCard'])->whereUuid(['membership', 'card']);
    });
});
