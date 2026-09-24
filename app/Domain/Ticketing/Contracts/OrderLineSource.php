<?php

namespace App\Domain\Ticketing\Contracts;

/**
 * Reads the sellable lines of a paid order for entitlement issuance (products of kind TICKET / RENTAL).
 * Default binding: DbOrderLineSource (reads order / order_line / product; foreign-key style lookups, never writes).
 * NullOrderLineSource is the fallback when the Orders tables are not installed.
 */
interface OrderLineSource
{
    /**
     * @return array{orderId: string, organizationId: string, siteId: string, holderName: ?string, facilityId: string, status: string, total: string, amountPaid: string, fullyPaid: bool, lines: list<array{lineId: string, productId: string, name: string, kind: string, quantity: int, facilityId: ?string, storeAvailable: bool}>}|null null when the order does not exist
     */
    public function forOrder(string $orderId): ?array;
}
