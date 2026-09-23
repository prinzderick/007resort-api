<?php

namespace App\Domain\Booking\Events;

/** A HELD/PENDING_PAYMENT booking passed its TTL and its slots were released. */
final class BookingHoldExpired
{
    public function __construct(public readonly string $bookingId) {}
}
