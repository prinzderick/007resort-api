<?php

use App\Domain\Booking\Http\Controllers\BookingController;
use App\Domain\Booking\Http\Controllers\ResourceController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1 with the `api` middleware group.
Route::middleware('auth:staff')->prefix('bookings')->group(function () {
    Route::get('resources', [ResourceController::class, 'index']);
    Route::post('resources', [ResourceController::class, 'store'])->middleware(['permission:booking.configure', 'idempotent']);
    Route::patch('resources/{resourceId}', [ResourceController::class, 'update'])->middleware(['permission:booking.configure', 'idempotent']);
    Route::post('resources/{resourceId}/blackouts', [ResourceController::class, 'blackout'])->middleware(['permission:booking.configure', 'idempotent']);
    Route::get('resources/{resourceId}/availability', [ResourceController::class, 'availability']);

    Route::post('hold', [BookingController::class, 'hold'])->middleware(['permission:booking.create', 'idempotent']);
    Route::get('/', [BookingController::class, 'index'])->middleware('permission:booking.view');
    Route::get('{bookingId}', [BookingController::class, 'show'])->middleware('permission:booking.view');
    Route::post('{bookingId}/confirm', [BookingController::class, 'confirm'])->middleware(['permission:booking.create', 'idempotent']);
    Route::post('{bookingId}/cancel', [BookingController::class, 'cancel'])->middleware(['permission:booking.cancel', 'idempotent']);
    Route::post('{bookingId}/reschedule', [BookingController::class, 'reschedule'])->middleware(['permission:booking.reschedule', 'idempotent']);
});
