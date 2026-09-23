<?php

namespace App\Domain\Inventory\Listeners;

use App\Domain\Inventory\Services\ConsumptionService;
use App\Domain\Inventory\Services\ProductStockLinks;
use App\Domain\Orders\Events\OrderSent;
use App\Domain\Orders\Events\OrderSettled;
use App\Domain\Orders\Events\OrderVoided;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Orders -> Inventory, synchronously INSIDE Orders' transaction (so a thrown `insufficient_stock` 409 rolls the send back and the
 * POS rejects the line). When stock is consumed follows the facility rule `stock_consumption_timing`:
 *   SEND   (default)  OrderSent    consumes the lines being sent.
 *   SETTLE            OrderSettled consumes all live lines of the order.
 * OrderVoided always restores whatever the order's lines had consumed (no-op when nothing was), so both timings are covered.
 * Every path is idempotent per order line: a re-delivered event never deducts or restores twice.
 */
class OrderStockListener
{
    public function __construct(private readonly ProductStockLinks $links, private readonly ConsumptionService $timing) {}

    public function sent(OrderSent $e): void
    {
        if ($this->timing->timingFor($e->facilityUnitId) !== 'SEND') {
            return;
        }
        $this->links->consumeOrderLines($e->facilityUnitId, $e->orderId, $e->lines, RequestContext::staffId());
    }

    public function settled(OrderSettled $e): void
    {
        if ($this->timing->timingFor($e->facilityUnitId) !== 'SETTLE') {
            return;
        }
        $lines = DB::table('order_line')->where('order_id', Ids::toBinary($e->orderId))->whereNotIn('status', ['VOIDED', 'REMOVED', 'PENDING'])
            ->orderBy('line_no')->get(['id', 'product_id', 'quantity'])
            ->map(fn ($l) => ['lineId' => Ids::fromBinary($l->id), 'productId' => Ids::fromBinary($l->product_id), 'qty' => (int) $l->quantity])->all();
        $this->links->consumeOrderLines($e->facilityUnitId, $e->orderId, $lines, RequestContext::staffId());
    }

    public function voided(OrderVoided $e): void
    {
        $this->links->restoreOrderLines($e->orderId, array_column($e->lines, 'lineId'), RequestContext::staffId());
    }
}
