<?php

use App\Domain\Config\Http\Controllers\DeviceAdminController;
use App\Domain\Config\Http\Controllers\FacilityAdminController;
use App\Domain\Config\Http\Controllers\OperatingPointAdminController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1 with the `api` middleware group. Permission-based only; every write is audited + outboxed (docs/CONFIG_ADMIN_API.md).
Route::middleware(['auth:staff', 'device:optional'])->group(function () {
    // ---- Facilities -------------------------------------------------------------------------------------------------------
    Route::get('organization/facility-templates', [FacilityAdminController::class, 'templates'])->middleware('permission:config.view|facility.manage');
    Route::get('organization/capability-types', [FacilityAdminController::class, 'capabilityTypes'])->middleware('permission:config.view|facility.manage');
    Route::get('organization/rule-definitions', [FacilityAdminController::class, 'ruleDefinitions'])->middleware('permission:config.view|config.manage.rules');

    Route::middleware('permission:facility.manage')->group(function () {
        Route::post('organization/facilities', [FacilityAdminController::class, 'store'])->middleware('idempotent');
        Route::patch('organization/facilities/{facilityId}', [FacilityAdminController::class, 'update']);
        Route::post('organization/facilities/{facilityId}/deactivate', [FacilityAdminController::class, 'deactivate']);
        Route::post('organization/facilities/{facilityId}/reactivate', [FacilityAdminController::class, 'reactivate']);
        Route::post('organization/facilities/{facilityId}/move', [FacilityAdminController::class, 'move']);
        Route::delete('organization/facilities/{facilityId}', [FacilityAdminController::class, 'destroy']);
    });

    // ---- Capabilities & operating rules -------------------------------------------------------------------------------------
    Route::put('facilities/{facilityId}/capabilities', [FacilityAdminController::class, 'setCapabilities'])->middleware('permission:config.manage.capabilities');
    Route::get('facilities/{facilityId}/operating-rules', [FacilityAdminController::class, 'operatingRules'])->middleware('permission:config.view|config.manage.rules');
    Route::put('facilities/{facilityId}/operating-rules', [FacilityAdminController::class, 'setOperatingRules'])->middleware('permission:config.manage.rules');

    // ---- Operating points, KDS stations, dining tables ----------------------------------------------------------------------
    Route::get('organization/operating-points', [OperatingPointAdminController::class, 'index'])->middleware('permission:config.view|facility.manage');
    Route::get('organization/tables', [OperatingPointAdminController::class, 'tables'])->middleware('permission:config.view|facility.manage|table.manage');
    Route::middleware('permission:facility.manage')->group(function () {
        Route::post('organization/facilities/{facilityId}/operating-points', [OperatingPointAdminController::class, 'store'])->middleware('idempotent');
        Route::patch('organization/operating-points/{id}', [OperatingPointAdminController::class, 'update']);
        Route::post('organization/operating-points/{id}/deactivate', [OperatingPointAdminController::class, 'deactivate']);
        Route::post('organization/operating-points/{id}/reactivate', [OperatingPointAdminController::class, 'reactivate']);

        Route::post('organization/facilities/{facilityId}/tables', [OperatingPointAdminController::class, 'storeTable'])->middleware('idempotent');
        Route::post('organization/facilities/{facilityId}/tables/bulk', [OperatingPointAdminController::class, 'bulkTables'])->middleware('idempotent');
        Route::patch('organization/tables/{id}', [OperatingPointAdminController::class, 'updateTable']);
        Route::post('organization/tables/{id}/deactivate', [OperatingPointAdminController::class, 'deactivateTable']);
        Route::post('organization/tables/{id}/reactivate', [OperatingPointAdminController::class, 'reactivateTable']);
        Route::post('organization/tables/{id}/merge', [OperatingPointAdminController::class, 'merge']);
        Route::post('organization/tables/{id}/unmerge', [OperatingPointAdminController::class, 'unmerge']);
    });

    // ---- Devices -------------------------------------------------------------------------------------------------------------
    Route::patch('devices/{deviceId}', [DeviceAdminController::class, 'update'])->middleware('permission:device.manage');
});
