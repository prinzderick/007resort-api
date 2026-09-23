<?php

namespace App\Domain\Catalog\Contracts;

/**
 * Optional hook so Inventory can report stock for availability (OUT_OF_STOCK, quantityOnHand).
 * Default binding (NullStockLevelProvider) reports "unknown". Inventory rebinds it in its service provider.
 */
interface StockLevelProvider
{
    /** On-hand quantity (decimal string) available to sell for a product at a facility, or null when not tracked/unknown. */
    public function onHand(string $productId, string $facilityId): ?string;
}
