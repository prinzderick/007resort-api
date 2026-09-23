<?php

use App\Domain\System\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('system/info', [SystemController::class, 'info']);
Route::get('system/health', [SystemController::class, 'ready']);
