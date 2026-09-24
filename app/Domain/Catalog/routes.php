<?php

use App\Domain\Catalog\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:staff,customer,service', 'device:optional', 'throttle:customer-api'])->group(function () {
    Route::get('catalog/products', [CatalogController::class, 'products']);
});

Route::middleware(['auth:staff', 'device:optional'])->group(function () {
    Route::get('catalog/categories', [CatalogController::class, 'categories']);
    Route::get('catalog/products/{productId}', [CatalogController::class, 'product'])->whereUuid('productId'); // 'export' is a Config route
    Route::get('catalog/availability', [CatalogController::class, 'availability']);
    Route::get('catalog/prep-routes', [CatalogController::class, 'prepRoutes']);
    Route::get('catalog/tax-rates', [CatalogController::class, 'taxRates']);
    Route::put('catalog/products/{productId}/availability/{facilityId}', [CatalogController::class, 'setAvailability'])
        ->middleware(['permission:catalog.availability.manage', 'idempotent']);

    // Admin catalog CRUD (additive to the contract; every write is audited).
    Route::middleware('permission:catalog.manage')->group(function () {
        Route::post('catalog/categories', [CatalogController::class, 'createCategory'])->middleware('idempotent');
        Route::patch('catalog/categories/{id}', [CatalogController::class, 'updateCategory'])->middleware('idempotent');
        Route::post('catalog/products', [CatalogController::class, 'createProduct'])->middleware('idempotent');
        Route::patch('catalog/products/{id}', [CatalogController::class, 'updateProduct'])->middleware('idempotent');
    });
    Route::put('catalog/products/{id}/price', [CatalogController::class, 'setPrice'])->middleware(['permission:pricing.manage', 'idempotent']);

});
