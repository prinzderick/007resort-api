<?php

namespace App\Domain\Inventory\Support;

/** One ledger leg: a signed quantity change of ONE item at ONE location. */
final class MovementSpec
{
    public function __construct(
        public readonly string $itemId,
        public readonly string $locationId,
        public readonly string $delta,
        public readonly string $reason,
        public readonly string $referenceType,
        public readonly string $referenceId,
        public readonly ?string $referenceLineId = null,
        public readonly ?string $counterpartLocationId = null,
        public readonly ?string $unitCost = null,
        public readonly ?string $note = null,
        public readonly ?string $dedupeKey = null,
        public readonly ?string $approvalId = null,
        public readonly ?string $actorStaffId = null,
    ) {}
}
