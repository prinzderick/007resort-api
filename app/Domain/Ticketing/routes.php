<?php

use App\Domain\Ticketing\Http\Controllers\EntitlementController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1 with the `api` middleware group.
Route::middleware(['auth:staff', 'device:optional'])->group(function () {
    Route::get('entitlements', [EntitlementController::class, 'index'])->middleware('permission:ticket.view');
    Route::post('entitlements', [EntitlementController::class, 'issue'])->middleware(['permission:ticket.issue', 'idempotent']);
    Route::get('entitlements/{entitlementId}', [EntitlementController::class, 'show'])->middleware('permission:ticket.view');
    Route::post('entitlements/{entitlementId}/release', [EntitlementController::class, 'release'])->middleware(['permission:ticket.release', 'idempotent']);
    Route::post('entitlements/{entitlementId}/return', [EntitlementController::class, 'return'])->middleware(['permission:ticket.release', 'idempotent']);

    Route::get('entitlement-tokens/{qrToken}', [EntitlementController::class, 'byToken'])->middleware('permission:ticket.view');
    Route::post('entitlement-tokens/{qrToken}/redeem', [EntitlementController::class, 'redeem'])->middleware(['permission:ticket.redeem', 'idempotent']);
    Route::post('entitlement-tokens/{qrToken}/exit', [EntitlementController::class, 'exit'])->middleware(['permission:ticket.redeem', 'idempotent']);
});
