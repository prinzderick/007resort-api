<?php

namespace App\Domain\Booking\Contracts;

use App\Domain\Booking\Support\HoldCommand;

/**
 * Local -> Cloud synchronous call: while the Internet is reachable, Cloud is the single decision point for
 * shared-pool bookings (booking-authority-and-offline-allocation.md §2). IMPLEMENTED BY THE SYNC MODULE.
 *
 * Contract: place the hold at Cloud (same UNIQUE guard) and return the booking snapshot (see BookingSnapshot::toArray);
 * throw ApiProblem 409 `slot_unavailable` when Cloud says the slot is gone; throw CloudUnreachableException when the
 * call cannot complete (Local then falls back to the resource's offline strategy A/B/C).
 */
interface CloudBookingAuthority
{
    public function isConfigured(): bool;

    /** @return array<string, mixed> booking snapshot (id, number, resourceId, start, end, quantity, unitNos, expiresAt, ...) */
    public function hold(HoldCommand $command): array;
}
