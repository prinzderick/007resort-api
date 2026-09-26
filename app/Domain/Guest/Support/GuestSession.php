<?php

namespace App\Domain\Guest\Support;

/** The guest order an `X-Order-Token` authorises (ids are canonical UUID strings). */
final class GuestSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $reference,
        public readonly string $kind,
        public readonly ?string $bookingId,
        public readonly ?string $orderId,
        public readonly ?string $membershipId,
        public readonly ?string $contactName,
        public readonly ?string $contactEmail,
        public readonly ?string $contactPhone,
    ) {}
}
