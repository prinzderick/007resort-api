<?php

namespace App\Domain\Booking\Support;

use Carbon\CarbonImmutable;

/** Input to BookingService::hold(). `channel` STAFF = Reception/admin, ONLINE = customer website (Cloud). */
final class HoldCommand
{
    /** @param array{name?: string, phone?: string, email?: string, membershipId?: string}|null $customer */
    public function __construct(
        public readonly string $resourceId,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly int $quantity = 1,
        public readonly bool $wholeResource = false,
        public readonly ?array $customer = null,
        public readonly string $channel = 'STAFF',
        public readonly ?string $staffId = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $bookingId = null,
        public readonly ?string $customerId = null,
    ) {}
}
