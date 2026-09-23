<?php

use App\Domain\Audit\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:staff', 'permission:audit.view'])->prefix('audit')->group(function () {
    Route::get('/', [AuditController::class, 'index']);
    Route::get('verify', [AuditController::class, 'verify']);
});
