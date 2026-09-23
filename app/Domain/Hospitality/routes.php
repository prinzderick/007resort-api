<?php

use App\Domain\Hospitality\Http\Controllers\KdsController;
use App\Domain\Hospitality\Http\Controllers\SystemInfoController;
use Illuminate\Support\Facades\Route;

Route::get('system/info', SystemInfoController::class); // public

Route::middleware('auth:staff')->group(function () {
    Route::get('kds/stations', [KdsController::class, 'stations']);
    Route::get('kds/stations/{stationId}/tickets', [KdsController::class, 'board'])->middleware('permission:prep_ticket.view');
    Route::get('prep-tickets/{ticketId}', [KdsController::class, 'show'])->middleware('permission:prep_ticket.view');
    Route::post('prep-tickets/{ticketId}/transition', [KdsController::class, 'transition'])->middleware(['permission:prep_ticket.transition', 'idempotent']);
});
