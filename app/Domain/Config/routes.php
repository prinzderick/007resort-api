<?php

use App\Domain\Config\Http\Controllers\BookingConfigController;
use App\Domain\Config\Http\Controllers\CatalogConfigController;
use App\Domain\Config\Http\Controllers\DeviceAdminController;
use App\Domain\Config\Http\Controllers\FacilityAdminController;
use App\Domain\Config\Http\Controllers\OperatingPointAdminController;
use App\Domain\Config\Http\Controllers\SettingsController;
use App\Domain\Config\Http\Controllers\TicketTypeAdminController;
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

    // ---- Catalogue configuration ----------------------------------------------------------------------------------------------
    Route::middleware('permission:catalog.manage|pricing.manage')->group(function () {
        Route::get('admin/catalog/products', [CatalogConfigController::class, 'products']);
        Route::get('admin/catalog/products/{productId}', [CatalogConfigController::class, 'product']);
    });
    Route::middleware('permission:catalog.manage')->group(function () {
        Route::put('catalog/products/{productId}/facilities/{facilityId}', [CatalogConfigController::class, 'setProductFacility']);
        Route::delete('catalog/products/{productId}/facilities/{facilityId}', [CatalogConfigController::class, 'removeProductFacility']);
        Route::post('catalog/tax-rates', [CatalogConfigController::class, 'createTaxRate'])->middleware('idempotent');
        Route::patch('catalog/tax-rates/{id}', [CatalogConfigController::class, 'updateTaxRate']);
        Route::post('catalog/prep-routes', [CatalogConfigController::class, 'createPrepRoute'])->middleware('idempotent');
        Route::patch('catalog/prep-routes/{id}', [CatalogConfigController::class, 'updatePrepRoute']);
        Route::get('catalog/prep-route-stations', [CatalogConfigController::class, 'prepRouteStations']);
        Route::put('catalog/prep-route-stations', [CatalogConfigController::class, 'setPrepRouteStation']);
        Route::post('catalog/categories/{id}/prep-route', [CatalogConfigController::class, 'categoryPrepRoute']);
        Route::get('catalog/products/{productId}/stock-links', [CatalogConfigController::class, 'stockLinks']);
        Route::put('catalog/products/{productId}/stock-links', [CatalogConfigController::class, 'setStockLinks']);
        Route::get('catalog/products/export', [CatalogConfigController::class, 'exportProducts']);
        Route::post('catalog/products/import', [CatalogConfigController::class, 'importProducts']);
    });
    Route::middleware('permission:pricing.manage')->group(function () {
        Route::get('catalog/price-lists', [CatalogConfigController::class, 'priceLists']);
        Route::post('catalog/price-lists', [CatalogConfigController::class, 'createPriceList'])->middleware('idempotent');
        Route::patch('catalog/price-lists/{id}', [CatalogConfigController::class, 'updatePriceList']);
        Route::get('catalog/prices', [CatalogConfigController::class, 'prices']);
        Route::post('catalog/prices', [CatalogConfigController::class, 'createPrice'])->middleware('idempotent');
        Route::patch('catalog/prices/{id}', [CatalogConfigController::class, 'updatePrice']);
        Route::get('catalog/prices/export', [CatalogConfigController::class, 'exportPrices']);
        Route::post('catalog/prices/import', [CatalogConfigController::class, 'importPrices']);
    });

    // ---- Ticket types ---------------------------------------------------------------------------------------------------------
    Route::get('ticketing/ticket-types', [TicketTypeAdminController::class, 'index'])->middleware('permission:config.view|ticket_type.manage|ticket.issue');
    Route::post('ticketing/ticket-types', [TicketTypeAdminController::class, 'store'])->middleware(['permission:ticket_type.manage', 'idempotent']);
    Route::patch('ticketing/ticket-types/{ticketTypeId}', [TicketTypeAdminController::class, 'update'])->middleware('permission:ticket_type.manage');

    // ---- Booking configuration ---------------------------------------------------------------------------------------------------
    Route::middleware('permission:booking.configure')->prefix('bookings')->group(function () {
        Route::get('resources/{resourceId}/schedule', [BookingConfigController::class, 'schedule']);
        Route::put('resources/{resourceId}/schedule', [BookingConfigController::class, 'setSchedule']);
        Route::get('resources/{resourceId}/blackouts', [BookingConfigController::class, 'blackouts']);
        Route::post('blackouts', [BookingConfigController::class, 'createBlackout'])->middleware('idempotent');
        Route::delete('blackouts/{blackoutId}', [BookingConfigController::class, 'deleteBlackout']);
        Route::get('resources/{resourceId}/rules', [BookingConfigController::class, 'rules']);
        Route::put('resources/{resourceId}/rules', [BookingConfigController::class, 'setRules']);
    });

    // ---- Settings ----------------------------------------------------------------------------------------------------------------------
    Route::get('admin/settings/business', [SettingsController::class, 'business'])->middleware('permission:config.view|settings.manage');
    Route::put('admin/settings/business', [SettingsController::class, 'updateBusiness'])->middleware('permission:settings.manage');
    Route::get('admin/settings/receipt', [SettingsController::class, 'receipt'])->middleware('permission:config.view|settings.manage');
    Route::put('admin/settings/receipt', [SettingsController::class, 'updateReceipt'])->middleware('permission:settings.manage');
    Route::get('facilities/{facilityId}/payment-methods', [SettingsController::class, 'paymentMethods'])->middleware('permission:config.view|settings.manage');
    Route::put('facilities/{facilityId}/payment-methods', [SettingsController::class, 'setPaymentMethods'])->middleware('permission:settings.manage');
});
