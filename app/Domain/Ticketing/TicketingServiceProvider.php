<?php

namespace App\Domain\Ticketing;

use App\Domain\Ticketing\Contracts\OrderLineSource;
use App\Domain\Ticketing\Contracts\RentalStockHook;
use App\Domain\Ticketing\Listeners\IssueEntitlementsForPaidOrder;
use App\Domain\Ticketing\Services\DbOrderLineSource;
use App\Domain\Ticketing\Services\NullRentalStockHook;
use App\Domain\Ticketing\Services\QrTokens;
use Illuminate\Support\Facades\Event;
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
        $this->app->bindIf(OrderLineSource::class, DbOrderLineSource::class);
    }

    public function boot(): void
    {
        // Event classes are referenced by name: Payments/Orders may not be installed in every build.
        foreach (['App\\Domain\\Payments\\Events\\PaymentCaptured', 'App\\Domain\\Orders\\Events\\OrderSettled'] as $event) {
            Event::listen($event, [IssueEntitlementsForPaidOrder::class, 'handle']);
        }
    }
}
