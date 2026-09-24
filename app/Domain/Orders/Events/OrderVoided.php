<?php

namespace App\Domain\Orders\Events;

/**
 * Dispatched inside the void transaction. `lines[].sent` says whether the line had been sent (stock consumed / prep committed)
 * so Inventory can post the compensating movement.
 */
final class OrderVoided
{
    /** @param list<array{lineId: string, productId: string, qty: int, sent: bool}> $lines */
    public function __construct(
        public readonly string $orderId,
        public readonly string $facilityUnitId,
        public readonly array $lines,
        public readonly string $reason,
        public readonly ?string $approvalId = null,
    ) {}
}
