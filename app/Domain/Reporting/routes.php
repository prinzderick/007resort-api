<?php

use App\Domain\Reporting\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1. Read-only. Fine-grained scope checks (facility/site) happen in the controller after the
// coarse "holds the permission somewhere" middleware.
Route::middleware(['auth:staff', 'device:optional'])->prefix('reports')->group(function () {
    Route::get('facility-daily-summary', [ReportController::class, 'facilityDailySummary'])->middleware('permission:report.view');
    Route::get('cashier-shift/{shiftId}', [ReportController::class, 'cashierShift'])->whereUuid('shiftId')->middleware('permission:report.view');
    Route::get('revenue', [ReportController::class, 'revenue'])->middleware('permission:report.view');
    Route::get('attendance-summary', [ReportController::class, 'attendanceSummary'])->middleware('permission:attendance.view');
    Route::get('membership-summary', [ReportController::class, 'membershipSummary'])->middleware('permission:report.view');
});
