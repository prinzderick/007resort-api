<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\BookingPaymentGateway;
use App\Support\Http\ApiProblem;
use App\Support\Money\Money;

/**
 * DEV / DEMO ONLY (config booking.payment_gateway=unlinked): accepts the tenders as stated and records only the
 * booking's `amount_paid`. No Payment rows, no cash-session accounting, no receipt. The Reception flow in production
 * goes through the Payments module (order -> payment -> BookingService::confirmPaid).
 */
final class UnlinkedPaymentGateway implements BookingPaymentGateway
{
    public function capture(string $bookingId, string $total, array $tenders, ?string $cashSessionId, ?string $paystackReference): array
    {
        if ($paystackReference !== null) {
            throw ApiProblem::unprocessable('provider_error', 'Paystack references are only supported when the Payments module is bound.');
        }
        $due = Money::of($total);
        $paid = Money::zero();
        foreach ($tenders as $tender) {
            $amount = Money::of($tender['amount'] ?? '0');
            if ($amount->isNegative() || $amount->isZero()) {
                throw ApiProblem::unprocessable('validation_failed', 'Tender amounts must be positive.');
            }
            $paid = $paid->add($amount);
        }
        if ($due->isZero() ? false : $paid->compare($due) !== 0) {
            throw new ApiProblem(422, 'amount_mismatch', "Tenders total {$paid->amount} but the booking total is {$due->amount}.", 'Amount mismatch', ['meta' => ['expected' => $due->amount, 'received' => $paid->amount]]);
        }

        return ['amountPaid' => $paid->amount, 'orderId' => null, 'reference' => null];
    }
}
