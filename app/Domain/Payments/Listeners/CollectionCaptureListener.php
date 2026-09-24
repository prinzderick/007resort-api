<?php

namespace App\Domain\Payments\Listeners;

use App\Domain\Payments\Events\PaymentCaptured;
use App\Domain\Payments\Services\CollectionService;

/**
 * A provider-verified capture (Paystack webhook / verify) of a waiter collection: record the PROVIDER decision and tell the collecting waiter's
 * device `payment.confirmed`. Manual captures (`MANUAL` provider) do this in CollectionService::capturePending themselves.
 */
final class CollectionCaptureListener
{
    public function __construct(private readonly CollectionService $collections) {}

    public function handle(PaymentCaptured $e): void
    {
        if ($e->provider !== 'MANUAL') {
            $this->collections->afterProviderCapture($e->paymentId);
        }
    }
}
