<?php

namespace App\Domain\Orders\Events;

/**
 * Plain-data domain event, dispatched synchronously INSIDE the send transaction so listeners (Inventory: SALE
 * stock consumption) can veto by throwing (e.g. ApiProblem insufficient_stock) and roll the send back.
 */
final class OrderSent
{
    /** @param list<array{lineId: string, productId: string, qty: int}> $lines */
    public function __construct(
        public readonly string $orderId,
        public readonly string $facilityUnitId,
        public readonly array $lines,
    ) {}
}
