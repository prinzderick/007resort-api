<?php

use App\Domain\Attendance\Http\Controllers\PunchController;
use Illuminate\Support\Facades\Route;

// ZKTeco ADMS / iClock push protocol. Terminals hit these fixed root paths (no /api prefix, no auth headers, plain text).
// Auth = registered + ACTIVE serial number (?SN=) and the optional ATTENDANCE_ICLOCK_ALLOWED_CIDRS source allow-list.
Route::middleware(['attendance.iclock', 'throttle:attendance-iclock'])->prefix('iclock')->group(function () {
    Route::match(['GET', 'POST'], 'cdata', [PunchController::class, 'cdata']);
    Route::match(['GET', 'POST'], 'cdata.aspx', [PunchController::class, 'cdata']);
    Route::get('getrequest', [PunchController::class, 'getRequest']);
    Route::post('devicecmd', [PunchController::class, 'deviceCmd']);
});
