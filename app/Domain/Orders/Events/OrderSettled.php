<?php

namespace App\Domain\Orders\Events;

/** Order fully paid and served (SETTLED). Money as decimal strings. Inside the settlement transaction. */
final class OrderSettled
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $facilityUnitId,
        public readonly string $total,
        public readonly string $amountPaid,
        public readonly ?string $tabId = null,
    ) {}
}
