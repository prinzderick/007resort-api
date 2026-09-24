<?php

use App\Domain\Payments\Http\Controllers\CashHandoverController;
use App\Domain\Payments\Http\Controllers\CashSessionController;
use App\Domain\Payments\Http\Controllers\CollectionController;
use App\Domain\Payments\Http\Controllers\PaymentController;
use App\Domain\Payments\Http\Controllers\ReceiptController;
use App\Domain\Payments\Http\Controllers\TerminalController;
use App\Domain\Payments\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1 with the `api` middleware group. Middleware order matters: auth -> permission (scoped, autocommit) ->
// idempotent (opens the transaction that also stores the response).

// Provider webhooks: NO bearer auth - authenticated by the provider's HMAC signature (Cloud node is the public receiver).
Route::post('payments/webhooks/{provider}', [WebhookController::class, 'receive']);
Route::post('payments/terminal-callbacks/{provider}', [CollectionController::class, 'terminalCallback']);

// Online customers pay through Paystack too (ownership of the booking / membership / order is enforced in PaystackService).
Route::middleware(['auth:staff,customer', 'device:optional', 'throttle:customer-api'])->group(function () {
    Route::post('payments/paystack/initialize', [PaymentController::class, 'paystackInitialize'])->middleware(['permission.public:payment.take', 'idempotent']);
    Route::get('payments/paystack/verify/{reference}', [PaymentController::class, 'paystackVerify']);
});

Route::middleware(['auth:staff', 'device:optional'])->group(function () {
    Route::post('payments', [PaymentController::class, 'store'])
        ->middleware(['permission:payment.take,facility=facilityId', 'idempotent']);
    Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payment.view|payment.confirm|payment.collect');
    Route::get('payments/{paymentId}', [PaymentController::class, 'show']);
    Route::post('payments/{paymentId}/refund', [PaymentController::class, 'refund'])
        ->middleware(['permission:refund.execute', 'idempotent']);
    Route::post('payments/{paymentId}/reversal', [PaymentController::class, 'reverse'])
        ->middleware(['permission:payment.reversal.execute', 'idempotent']);

    // Facility scope of a tab is resolved inside the service (post-lock); the route only requires the permission somewhere.
    Route::post('tabs/{tabId}/settle', [PaymentController::class, 'settleTab'])
        ->middleware(['permission:order.settle', 'idempotent']);

    Route::post('cash-sessions', [CashSessionController::class, 'open'])
        ->middleware(['permission:cash_session.open,facility=facilityId', 'idempotent']);
    Route::get('cash-sessions', [CashSessionController::class, 'index'])->middleware('permission:cash_session.view');
    Route::get('cash-sessions/{cashSessionId}', [CashSessionController::class, 'show']);
    Route::post('cash-sessions/{cashSessionId}/close', [CashSessionController::class, 'close'])
        ->middleware(['permission:cash_session.close', 'idempotent']);
    Route::post('cash-sessions/{cashSessionId}/movements', [CashSessionController::class, 'movement'])
        ->middleware(['permission:cash_movement.record', 'idempotent']);

    // ---- Waiter collection (docs/WAITER_COLLECTION.md). Facility-scoped permission checks happen in the services (post-lock). ----
    Route::post('orders/{orderId}/collections', [CollectionController::class, 'collect'])->middleware(['permission:payment.collect', 'idempotent']);
    Route::post('payments/{paymentId}/confirm', [CollectionController::class, 'confirm'])->middleware(['permission:payment.confirm', 'idempotent']);
    Route::post('payments/{paymentId}/reject', [CollectionController::class, 'reject'])->middleware(['permission:payment.confirm', 'idempotent']);
    Route::post('payments/{paymentId}/cancel', [CollectionController::class, 'cancel'])->middleware(['permission:payment.collect|payment.confirm', 'idempotent']);

    Route::get('payment-terminals', [TerminalController::class, 'index'])->middleware('permission:payment.collect|device.manage');
    Route::post('payment-terminals', [TerminalController::class, 'store'])->middleware(['permission:device.manage', 'idempotent']);
    Route::get('payment-terminals/{terminalId}', [TerminalController::class, 'show'])->middleware('permission:payment.collect|device.manage');
    Route::patch('payment-terminals/{terminalId}', [TerminalController::class, 'update'])->middleware(['permission:device.manage', 'idempotent']);

    Route::post('cash-handovers', [CashHandoverController::class, 'declare'])->middleware(['permission:cash_handover.create', 'idempotent']);
    Route::get('cash-handovers', [CashHandoverController::class, 'index']);
    Route::get('cash-handovers/{handoverId}', [CashHandoverController::class, 'show']);
    Route::post('cash-handovers/{handoverId}/receive', [CashHandoverController::class, 'receive'])->middleware(['permission:cash_handover.receive', 'idempotent']);
    Route::post('cash-handovers/{handoverId}/signoff', [CashHandoverController::class, 'signoff'])->middleware(['permission:cash_handover.signoff', 'idempotent']);
    Route::get('staff/{staffId}/cash-in-hand', [CashHandoverController::class, 'cashInHand']);
    Route::get('staff/{staffId}/collection-policy', [CashHandoverController::class, 'showPolicy']);
    Route::patch('staff/{staffId}/collection-policy', [CashHandoverController::class, 'updatePolicy'])->middleware(['permission:staff.manage', 'idempotent']);

    Route::get('receipts/{receiptId}', [ReceiptController::class, 'show']);
    Route::get('orders/{orderId}/receipt', [ReceiptController::class, 'forOrder']);
});
