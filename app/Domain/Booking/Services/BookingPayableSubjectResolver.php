<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Payments\Contracts\PayableSubjectResolver;
use App\Support\Ids;
use App\Support\Money\Money;

/** Lets Payments (Paystack) take payment for a booking itself: amount due = total - paid, only while the hold is live. */
final class BookingPayableSubjectResolver implements PayableSubjectResolver
{
    public function resolve(string $type, string $id): ?array
    {
        if ($type !== 'BOOKING' || ! Ids::isUuid($id)) {
            return null;
        }
        $b = Booking::query()->find(strtolower($id));
        if ($b === null || ! in_array($b->status, [Booking::HELD, Booking::PENDING_PAYMENT], true) || ! $b->isHoldLive()) {
            return null;
        }
        $due = Money::of($b->total)->sub(Money::of($b->amount_paid));

        return $due->isZero() || $due->isNegative() ? null : ['amountDue' => $due->amount, 'facilityId' => $b->facility_unit_id];
    }
}
