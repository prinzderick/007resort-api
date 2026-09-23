<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Contracts\StockLevelProvider;

final class NullStockLevelProvider implements StockLevelProvider
{
    public function onHand(string $productId, string $facilityId): ?string
    {
        return null;
    }
}
