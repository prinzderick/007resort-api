<?php

use App\Domain\Organization\Http\Controllers\OrganizationController;
use App\Domain\Organization\Http\Controllers\TaxSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:staff')->group(function () {
    Route::get('organization/site', [OrganizationController::class, 'site']);
    Route::get('organization/facilities', [OrganizationController::class, 'facilities']);
    Route::get('organization/facilities/{facilityId}', [OrganizationController::class, 'facility']);
    Route::get('organization/facilities/{facilityId}/operating-points', [OrganizationController::class, 'operatingPoints']);
    Route::get('facilities/{facilityId}/capabilities', [OrganizationController::class, 'capabilities']);

    // ADR-0011: VAT is admin-settable (default off). Audited; If-Match optimistic concurrency.
    Route::get('admin/settings/tax', [TaxSettingController::class, 'show'])->middleware('permission:config.manage');
    Route::put('admin/settings/tax', [TaxSettingController::class, 'update'])->middleware(['permission:config.manage', 'idempotent']);
});
