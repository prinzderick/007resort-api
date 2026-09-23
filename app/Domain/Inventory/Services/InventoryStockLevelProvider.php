<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Contracts\StockLevelProvider;
use App\Domain\Inventory\Support\Qty;
use App\Support\Http\ApiProblem;

/**
 * Catalog availability hook: units of a product that can be sold now at a facility = the tightest of its linked stock items
 * (on hand at the facility's sale location / quantity_per_unit). null = the product is not stock-linked or the facility has no store.
 */
class InventoryStockLevelProvider implements StockLevelProvider
{
    public function __construct(private readonly ProductStockLinks $links, private readonly ConsumptionService $consumption, private readonly StockLedger $ledger) {}

    public function onHand(string $productId, string $facilityId): ?string
    {
        $links = $this->links->linksFor([$productId])[$productId] ?? [];
        if ($links === []) {
            return null;
        }
        try {
            $location = $this->consumption->saleLocation($facilityId);
        } catch (ApiProblem) {
            return null;
        }
        $min = null;
        foreach ($links as $l) {
            $units = bcdiv($this->ledger->onHand($l['itemId'], $location), $l['perUnit'], Qty::SCALE);
            $min = $min === null || Qty::cmp($units, $min) < 0 ? $units : $min;
        }

        return $min;
    }
}
