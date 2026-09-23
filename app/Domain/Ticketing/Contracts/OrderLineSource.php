<?php

namespace App\Domain\Ticketing\Contracts;

/**
 * Reads the sellable lines of a paid order for entitlement issuance (products of kind TICKET / RENTAL).
 * Bound by the Orders/Catalog integration; the default (NullOrderLineSource) reports "orders not installed".
 */
interface OrderLineSource
{
    /**
     * @return array{orderId: string, holderName: ?string, facilityId: string, lines: list<array{lineId: string, productId: string, name: string, kind: string, quantity: int, facilityId: ?string}>}|null null when the order does not exist
     */
    public function forOrder(string $orderId): ?array;
}
