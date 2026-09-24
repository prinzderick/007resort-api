<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\BookingPaymentGateway;
use App\Support\Http\ApiProblem;

/** Production default until the Payments module binds a real BookingPaymentGateway: refuse rather than fake a payment. */
final class UnboundPaymentGateway implements BookingPaymentGateway
{
    public function capture(string $bookingId, string $total, array $tenders, ?string $cashSessionId, ?string $paystackReference): array
    {
        throw new ApiProblem(501, 'provider_error', 'No payment gateway is bound for bookings (Payments module not installed).', 'Not implemented');
    }
}
