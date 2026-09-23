<?php

namespace App\Domain\Inventory;

use App\Domain\Catalog\Contracts\StockLevelProvider;
use App\Domain\Inventory\Console\DemoSeedCommand;
use App\Domain\Inventory\Console\ReconcileCommand;
use App\Domain\Inventory\Contracts\InventoryConsumption;
use App\Domain\Inventory\Contracts\RentalGateway;
use App\Domain\Inventory\Database\InventoryDemoSeeder;
use App\Domain\Inventory\Listeners\OrderStockListener;
use App\Domain\Inventory\Services\AdjustmentApprovalHandler;
use App\Domain\Inventory\Services\AdjustmentService;
use App\Domain\Inventory\Services\ConsumptionService;
use App\Domain\Inventory\Services\CountService;
use App\Domain\Inventory\Services\InventoryAccess;
use App\Domain\Inventory\Services\InventoryStockLevelProvider;
use App\Domain\Inventory\Services\ProductStockLinks;
use App\Domain\Inventory\Services\ReconciliationService;
use App\Domain\Inventory\Services\RentalService;
use App\Domain\Inventory\Services\StockDocuments;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Orders\Approvals\ApprovalService;
use App\Domain\Orders\Events\OrderSent;
use App\Domain\Orders\Events\OrderSettled;
use App\Domain\Orders\Events\OrderVoided;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Inventory module (ADR-0002). Public surface for other modules:
 *   - {@see InventoryConsumption}  Orders (and anything selling stock) deducts / restores stock inside its own transaction.
 *   - {@see RentalGateway}         Ticketing's Sports Store release / return.
 *   - approvals: registers the `inventory.adjustment` handler with Orders' ApprovalService.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StockLedger::class);
        $this->app->singleton(InventoryAccess::class);
        $this->app->singleton(StockDocuments::class);
        $this->app->singleton(AdjustmentService::class);
        $this->app->singleton(CountService::class);
        $this->app->singleton(ReconciliationService::class);
        $this->app->singleton(ConsumptionService::class);
        $this->app->singleton(RentalService::class);
        $this->app->singleton(ProductStockLinks::class);
        $this->app->bind(StockLevelProvider::class, InventoryStockLevelProvider::class); // replaces Catalog's NullStockLevelProvider
        $this->app->bind(InventoryConsumption::class, ConsumptionService::class);
        $this->app->bind(RentalGateway::class, RentalService::class);
    }

    public function boot(): void
    {
        $this->app->make(ApprovalService::class)
            ->registerHandler('inventory.adjustment', new AdjustmentApprovalHandler($this->app->make(AdjustmentService::class)));

        // Orders -> stock (sync, inside Orders' transaction). Facility rule stock_consumption_timing decides SEND vs SETTLE.
        Event::listen(OrderSent::class, [OrderStockListener::class, 'sent']);
        Event::listen(OrderSettled::class, [OrderStockListener::class, 'settled']);
        Event::listen(OrderVoided::class, [OrderStockListener::class, 'voided']);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileCommand::class, DemoSeedCommand::class]);
        }

        // Nightly invariant check: stock_balance == SUM(stock_movement).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('r007:inventory:reconcile')
                ->dailyAt((string) config('inventory.reconcile_at', '02:30'))->timezone('Africa/Lagos')
                ->withoutOverlapping()->onOneServer();
        });

        // Hook into `php artisan r007:demo-seed` (owned elsewhere) without editing it: seed demo stock after it finishes.
        Event::listen(CommandFinished::class, function (CommandFinished $e): void {
            if ($e->command === 'r007:demo-seed' && $e->exitCode === 0) {
                (new InventoryDemoSeeder)->run();
            }
        });
    }
}
