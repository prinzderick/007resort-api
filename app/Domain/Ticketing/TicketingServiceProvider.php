<?php

namespace App\Domain\Ticketing;

use App\Domain\Ticketing\Contracts\OrderLineSource;
use App\Domain\Ticketing\Contracts\RentalStockHook;
use App\Domain\Ticketing\Services\NullOrderLineSource;
use App\Domain\Ticketing\Services\NullRentalStockHook;
use App\Domain\Ticketing\Services\QrTokens;
use Illuminate\Support\ServiceProvider;

/**
 * Ticketing module. Seams: RentalStockHook (Inventory), OrderLineSource (Orders/Catalog) — no-op defaults via bindIf.
 */
class TicketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QrTokens::class);
        $this->app->bindIf(RentalStockHook::class, NullRentalStockHook::class);
        $this->app->bindIf(OrderLineSource::class, NullOrderLineSource::class);
    }

    public function boot(): void
    {
        //
    }
}
