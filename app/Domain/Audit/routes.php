<?php

use App\Domain\Audit\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

// GET /audit: `audit.view` sees everything; `config.view` holders see the configuration entity types only (change-history panels on config screens).
Route::middleware(['auth:staff'])->prefix('audit')->group(function () {
    Route::get('/', [AuditController::class, 'index'])->middleware('permission:audit.view|config.view');
    Route::get('verify', [AuditController::class, 'verify'])->middleware('permission:audit.view');
});
