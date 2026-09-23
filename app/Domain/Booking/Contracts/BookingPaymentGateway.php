<?php

namespace App\Domain\Booking\Contracts;

/**
 * Captures payment for a booking at confirm time. The Payments/Orders modules bind the real implementation
 * (Reception flow: order lines -> payment -> confirm). See UnlinkedPaymentGateway for the dev/demo fallback.
 */
interface BookingPaymentGateway
{
    /**
     * @param  list<array<string, mixed>>  $tenders  contract TenderInput[] (camelCase)
     * @return array{amountPaid: string, orderId: ?string, reference: ?string}
     *
     * @throws \App\Support\Http\ApiProblem amount_mismatch / cash_session_required / payment_state_invalid / provider_error
     */
    public function capture(string $bookingId, string $total, array $tenders, ?string $cashSessionId, ?string $paystackReference): array;
}
