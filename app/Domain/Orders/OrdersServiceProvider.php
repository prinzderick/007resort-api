<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Approvals\ApprovalService;
use App\Domain\Orders\Approvals\BillCancelHandler;
use App\Domain\Orders\Approvals\OrderApprovalHandlers;
use App\Domain\Orders\Services\BillService;
use App\Domain\Orders\Services\OperatingRules;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Orders\Services\OrderSettlementService;
use App\Domain\Orders\Services\Presenter;
use App\Domain\Orders\Services\Realtime;
use App\Domain\Orders\Services\TableService;
use App\Domain\Orders\Services\TabService;
use Illuminate\Support\ServiceProvider;

/**
 * Orders module. Auto-registered by App\Providers\ModuleServiceProvider (see docs/MODULES.md).
 * Other modules integrate through: events (Events\OrderSent / OrderVoided / OrderSettled), OrderSettlementService (Payments),
 * ApprovalService::registerHandler() (new approval actions).
 */
class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([Presenter::class, Realtime::class, OperatingRules::class, TableService::class, TabService::class, OrderService::class, OrderSettlementService::class, ApprovalService::class, BillService::class] as $s) {
            $this->app->singleton($s);
        }
    }

    public function boot(): void
    {
        $approvals = $this->app->make(ApprovalService::class);
        $orders = $this->app->make(OrderService::class);
        $approvals->registerHandler('order.void', OrderApprovalHandlers::void($orders));
        $approvals->registerHandler('order.adjust', OrderApprovalHandlers::adjust($orders));
        $approvals->registerHandler(BillService::CANCEL_ACTION, new BillCancelHandler($this->app->make(BillService::class)));
    }
}
