<?php

namespace App\Domain\Inventory\Console;

use App\Domain\Inventory\Database\InventoryDemoSeeder;
use Illuminate\Console\Command;

class DemoSeedCommand extends Command
{
    protected $signature = 'r007:inventory:demo-seed';

    protected $description = 'Seed demo inventory: Main Store + facility sub-stores, ~60 items, opening stock (also runs after r007:demo-seed)';

    public function handle(): int
    {
        $r = (new InventoryDemoSeeder)->run();
        $this->info("Inventory demo data ready: {$r['items']} items, {$r['locations']} locations".($r['openingStockPosted'] ? ', opening stock posted.' : ' (stock already present).'));

        return self::SUCCESS;
    }
}
