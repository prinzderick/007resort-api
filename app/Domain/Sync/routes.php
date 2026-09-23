<?php

use App\Domain\Sync\Http\Controllers\AdminController;
use App\Domain\Sync\Http\Controllers\NodeController;
use Illuminate\Support\Facades\Route;

Route::prefix('sync')->group(function () {
    // Node-to-node (node credential, not a staff/device token). Naturally idempotent (event_id dedup).
    Route::middleware('node.auth')->group(function () {
        Route::post('inbox', [NodeController::class, 'inbox']);
        Route::get('pull', [NodeController::class, 'pull']);
        Route::post('pull/ack', [NodeController::class, 'ack']);
        Route::post('heartbeat', [NodeController::class, 'heartbeat']);
    });

    // IT / Admin (staff bearer + permission config.manage, per the contract).
    Route::middleware(['auth:staff', 'permission:config.manage'])->group(function () {
        Route::get('status', [AdminController::class, 'status']);
        Route::get('conflicts', [AdminController::class, 'conflicts']);
        Route::get('conflicts/{id}', [AdminController::class, 'conflict']);
        Route::post('conflicts/{id}/resolve', [AdminController::class, 'resolveConflict'])->middleware('idempotent');
        Route::post('conflicts/{id}/reprocess', [AdminController::class, 'reprocessConflict'])->middleware('idempotent');
        Route::get('outbox', [AdminController::class, 'outbox']);
        Route::post('outbox/replay-failed', [AdminController::class, 'replayFailed'])->middleware('idempotent');
        Route::post('outbox/{id}/retry', [AdminController::class, 'retryOutbox'])->middleware('idempotent');
        Route::get('inbox-events', [AdminController::class, 'inboxEvents']);
        Route::post('inbox-events/{id}/reprocess', [AdminController::class, 'reprocessInbox'])->middleware('idempotent');
    });
});
