<?php

use App\Domain\Attendance\Http\Controllers\AttendanceController;
use App\Domain\Attendance\Http\Controllers\PunchController;
use App\Domain\Attendance\Http\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1 with the `api` middleware group. (ZKTeco ADMS root routes: routes-iclock.php.)

// Terminal / bridge push: X-Device-Token only, no staff session.
Route::post('attendance/punches', [PunchController::class, 'ingest'])->middleware(['attendance.terminal', 'throttle:attendance-terminal']);

Route::middleware('auth:staff')->prefix('attendance')->group(function () {
    Route::get('/', [AttendanceController::class, 'index'])->middleware('permission:attendance.view');
    Route::get('punches', [AttendanceController::class, 'punches'])->middleware('permission:attendance.view');

    Route::middleware('permission:attendance.device.manage')->group(function () {
        Route::get('devices', [TerminalController::class, 'index']);
        Route::post('devices', [TerminalController::class, 'store']); // returns a one-time secret: deliberately not idempotency-stored
        Route::post('devices/{device}/rotate-token', [TerminalController::class, 'rotateToken'])->whereUuid('device');
        Route::post('devices/{device}/status', [TerminalController::class, 'setStatus'])->whereUuid('device')->middleware('idempotent');
        Route::get('links', [TerminalController::class, 'links']);
        Route::post('links', [TerminalController::class, 'link'])->middleware('idempotent');
        Route::delete('links/{link}', [TerminalController::class, 'unlink'])->whereUuid('link')->middleware('idempotent');
    });

    Route::get('corrections', [AttendanceController::class, 'corrections'])->middleware('permission:attendance.view');
    Route::post('corrections', [AttendanceController::class, 'requestCorrection'])->middleware(['permission:attendance.correction.request', 'idempotent']);
    Route::post('corrections/{correction}/approve', [AttendanceController::class, 'approve'])->whereUuid('correction')->middleware(['permission:staff.clock_correction.approve', 'idempotent']);
    Route::post('corrections/{correction}/reject', [AttendanceController::class, 'reject'])->whereUuid('correction')->middleware(['permission:staff.clock_correction.approve', 'idempotent']);
});
