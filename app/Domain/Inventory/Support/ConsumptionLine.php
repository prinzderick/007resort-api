<?php

namespace App\Domain\Inventory\Support;

/** One stock deduction request. `lineRef` (order-line id) makes it idempotent and reversible per line. */
final class ConsumptionLine
{
    public function __construct(
        public readonly string $itemId,
        public readonly string $quantity,
        public readonly string $lineRef,
        public readonly ?string $locationId = null,
    ) {}
}
