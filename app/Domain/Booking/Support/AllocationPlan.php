<?php

namespace App\Domain\Booking\Support;

/**
 * Outcome of the Booking Authority policy: which pool and which unit numbers this hold may draw from, or
 * `delegateToCloud` (Local asks the Cloud authority instead of deciding itself).
 */
final class AllocationPlan
{
    public function __construct(
        public readonly string $pool,          // 'CLOUD' | 'LOCAL'  (slot_allocation.pool)
        public readonly int $unitLo,
        public readonly int $unitHi,
        public readonly bool $delegateToCloud = false,
        public readonly string $reason = '',
    ) {}
}
