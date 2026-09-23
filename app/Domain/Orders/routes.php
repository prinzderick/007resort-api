<?php

use App\Domain\Orders\Http\Controllers\ApprovalController;
use App\Domain\Orders\Http\Controllers\OrderController;
use App\Domain\Orders\Http\Controllers\TabController;
use App\Domain\Orders\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:staff', 'device:optional'])->group(function () {
    // Dining tables
    Route::get('tables', [TableController::class, 'index']);
    Route::get('tables/{tableId}', [TableController::class, 'show']);
    Route::patch('tables/{tableId}', [TableController::class, 'update'])->middleware(['permission:table.manage', 'idempotent']);
    Route::post('tables/{tableId}/open', [TableController::class, 'open'])->middleware(['permission:order.create', 'idempotent']);
    Route::post('tables/{tableId}/assign', [TableController::class, 'assign'])->middleware(['permission:table.manage', 'idempotent']);
    Route::post('tables/{tableId}/transfer', [TableController::class, 'transfer'])->middleware(['permission:table.manage', 'idempotent']);

    // Orders
    Route::post('orders', [OrderController::class, 'store'])->middleware(['permission:order.create,facility=facilityId', 'idempotent']);
    Route::get('orders', [OrderController::class, 'index'])->middleware('permission:order.view');
    Route::get('orders/{orderId}', [OrderController::class, 'show'])->middleware('permission:order.view');
    Route::post('orders/{orderId}/lines', [OrderController::class, 'addLine'])->middleware(['permission:order.line.add', 'idempotent']);
    Route::delete('orders/{orderId}/lines/{lineId}', [OrderController::class, 'removeLine'])->middleware(['permission:order.line.remove_unsent', 'idempotent']);
    Route::post('orders/{orderId}/send', [OrderController::class, 'send'])->middleware(['permission:order.send', 'idempotent']);
    Route::post('orders/{orderId}/serve', [OrderController::class, 'serve'])->middleware(['permission:order.serve', 'idempotent']);
    // void/adjust: the "may request" vs "may execute" split is decided in the service (permission gate + approval workflow)
    Route::post('orders/{orderId}/void', [OrderController::class, 'void'])->middleware('idempotent');
    Route::post('orders/{orderId}/lines/{lineId}/adjustments', [OrderController::class, 'adjust'])->middleware('idempotent');

    // Tabs
    Route::post('tabs', [TabController::class, 'store'])->middleware(['permission:tab.open,facility=facilityId', 'idempotent']);
    Route::get('tabs', [TabController::class, 'index'])->middleware('permission:tab.view_own_facility');
    Route::get('tabs/{tabId}', [TabController::class, 'show'])->middleware('permission:tab.view_own_facility');
    Route::post('tabs/{tabId}/orders', [TabController::class, 'addOrders'])->middleware(['permission:tab.open', 'idempotent']);

    // Approvals
    Route::get('approvals', [ApprovalController::class, 'index']);
    Route::get('approvals/{approvalId}', [ApprovalController::class, 'show']);
    Route::post('approvals/{approvalId}/decision', [ApprovalController::class, 'decide'])->middleware('idempotent');
    Route::post('approvals/{approvalId}/cancel', [ApprovalController::class, 'cancel'])->middleware('idempotent');
});
