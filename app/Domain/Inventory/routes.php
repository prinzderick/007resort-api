<?php

use App\Domain\Inventory\Http\Controllers\InventoryController;
use App\Domain\Inventory\Http\Controllers\StockOperationsController;
use Illuminate\Support\Facades\Route;

// Inventory module (contract: api/openapi/v1.yaml tag Inventory). `permission:` = held somewhere; controllers/services then
// re-check it against the scope of the location(s) touched.
Route::middleware('auth:staff')->prefix('inventory')->group(function () {
    // --- reads ---
    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('items', [InventoryController::class, 'items']);
        Route::get('locations', [InventoryController::class, 'locations']);
        Route::get('balances', [InventoryController::class, 'balances']);
        Route::get('movements', [InventoryController::class, 'movements']);
        Route::get('adjustments', [StockOperationsController::class, 'listAdjustments']);
        Route::get('adjustments/{adjustment}', [StockOperationsController::class, 'getAdjustment']);
        Route::get('counts', [StockOperationsController::class, 'listCounts']);
        Route::get('counts/{count}', [StockOperationsController::class, 'getCount']);
        Route::get('rental-assets', [InventoryController::class, 'rentalAssets']);
    });
    Route::get('suppliers', [InventoryController::class, 'suppliers'])->middleware('permission:supplier.manage');

    // --- master data ---
    Route::post('items', [InventoryController::class, 'createItem'])->middleware(['permission:inventory.item.manage', 'idempotent']);
    Route::patch('items/{item}', [InventoryController::class, 'updateItem'])->middleware(['permission:inventory.item.manage', 'idempotent']);
    Route::post('locations', [InventoryController::class, 'createLocation'])->middleware(['permission:inventory.location.manage', 'idempotent']);
    Route::patch('locations/{location}', [InventoryController::class, 'updateLocation'])->middleware(['permission:inventory.location.manage', 'idempotent']);
    Route::post('suppliers', [InventoryController::class, 'createSupplier'])->middleware(['permission:supplier.manage', 'idempotent']);
    Route::post('rental-assets', [InventoryController::class, 'createRentalAsset'])->middleware(['permission:inventory.item.manage', 'idempotent']);

    // --- stock movements ---
    Route::post('purchase-receipts', [StockOperationsController::class, 'purchaseReceipt'])->middleware(['permission:inventory.purchase_receipt.create', 'idempotent']);
    Route::post('transfers', [StockOperationsController::class, 'transfer'])->middleware(['permission:inventory.transfer.create', 'idempotent']);
    Route::post('adjustments', [StockOperationsController::class, 'adjustment'])->middleware(['permission:inventory.adjustment.request', 'idempotent']);
    Route::post('adjustments/{adjustment}/decision', [StockOperationsController::class, 'decideAdjustment'])->middleware(['permission:inventory.adjustment.approve', 'idempotent']);
    Route::post('wastage', [StockOperationsController::class, 'wastage'])->middleware(['permission:inventory.wastage.create', 'idempotent']);
    Route::post('returns', [StockOperationsController::class, 'stockReturn'])->middleware(['permission:inventory.return.create', 'idempotent']);

    // --- counts ---
    Route::post('counts', [StockOperationsController::class, 'createCount'])->middleware(['permission:inventory.count.create', 'idempotent']);
    Route::post('counts/{count}/post', [StockOperationsController::class, 'postCount'])->middleware(['permission:inventory.count.post', 'idempotent']);
});
