<?php

namespace App\Domain\Ticketing;

use App\Domain\Inventory\Contracts\RentalGateway;
use App\Domain\Ticketing\Contracts\OrderLineSource;
use App\Domain\Ticketing\Contracts\RentalStockHook;
use App\Domain\Ticketing\Listeners\IssueEntitlementsForPaidOrder;
use App\Domain\Ticketing\Services\DbOrderLineSource;
use App\Domain\Ticketing\Services\InventoryRentalStockHook;
use App\Domain\Ticketing\Services\NullRentalStockHook;
use App\Domain\Ticketing\Services\QrTokens;
use App\Domain\Ticketing\Sync\TicketingEventApplier;
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
        // Sync module integration: inbox appliers for the other node's ticketing events.
        $this->callAfterResolving('App\\Domain\\Sync\\Services\\SyncApplierRegistry', function ($registry): void {
            $registry->register(TicketingEventApplier::EVENT_TYPES, TicketingEventApplier::class);
        });
        // Inventory's rental ledger (pooled quantities) replaces the no-op hook once that module is installed; bound after every provider registered.
        $this->app->booted(function (): void {
            if (interface_exists(RentalGateway::class)) {
                $this->app->bind(RentalStockHook::class, InventoryRentalStockHook::class);
            }
        });

        // Event classes are referenced by name: Payments/Orders may not be installed in every build.
        foreach (['App\\Domain\\Payments\\Events\\PaymentCaptured', 'App\\Domain\\Orders\\Events\\OrderSettled'] as $event) {
            Event::listen($event, [IssueEntitlementsForPaidOrder::class, 'handle']);
        }
    }
}
