<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Contracts\StockLevelProvider;
use App\Domain\Catalog\Services\CatalogAdmin;
use App\Domain\Catalog\Services\CatalogService;
use App\Domain\Catalog\Services\NullStockLevelProvider;
use App\Domain\Catalog\Services\Pricing;
use Illuminate\Support\ServiceProvider;

/** Catalog module. Inventory may rebind StockLevelProvider to report availability/OUT_OF_STOCK. */
class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Pricing::class);
        $this->app->singleton(CatalogAdmin::class);
        $this->app->singleton(CatalogService::class);
        $this->app->bindIf(StockLevelProvider::class, NullStockLevelProvider::class);
    }
}
