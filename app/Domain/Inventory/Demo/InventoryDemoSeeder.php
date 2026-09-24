<?php

namespace App\Domain\Inventory\Demo;

use App\Domain\Inventory\Database\InventoryDemoSeeder as StockSeeder;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoSeeder;

/** Integrated demo: stock locations + items + opening stock via real receipts/transfers, linked to Catalog products (product_stock_link). Runs after Catalog (100). */
class InventoryDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 120;
    }

    public function run(DemoContext $context): void
    {
        $r = (new StockSeeder)->run();
        $context->info("  inventory: {$r['items']} items, {$r['locations']} locations, {$r['productLinks']} product links".($r['openingStockPosted'] ? ', opening stock posted' : ' (stock already present)'));
    }
}
