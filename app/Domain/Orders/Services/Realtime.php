<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Broadcast\OrderReady;
use App\Domain\Orders\Broadcast\OrderUpdated;
use App\Domain\Orders\Broadcast\TableUpdated;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Builds and dispatches realtime hints (api/realtime.md). Call inside the business transaction: delivery is after commit. */
final class Realtime
{
    public function __construct(private readonly Presenter $presenter) {}

    public function facilityOrders(string $facilityId): string
    {
        return 'facility.'.$facilityId.'.orders';
    }

    public function orderUpdated(object $orderRow, array $changed = ['status']): void
    {
        $fresh = DB::table('order')->where('id', $orderRow->id)->first();
        event(new OrderUpdated([$this->facilityOrders(Ids::fromBinary($fresh->facility_unit_id))], [
            'order' => $this->presenter->summary($fresh),
            'changed' => $changed,
            'rowVersion' => (int) $fresh->row_version,
        ]));
    }

    /** @param list<string> $stationIds */
    public function orderReady(object $orderRow, array $stationIds): void
    {
        $fresh = DB::table('order')->where('id', $orderRow->id)->first();
        $label = $fresh->dining_table_id ? DB::table('dining_table')->where('id', $fresh->dining_table_id)->value('label') : null;
        event(new OrderReady([$this->facilityOrders(Ids::fromBinary($fresh->facility_unit_id))], [
            'orderId' => Ids::fromBinary($fresh->id),
            'orderNumber' => $fresh->order_number,
            'tableId' => $fresh->dining_table_id ? Ids::fromBinary($fresh->dining_table_id) : null,
            'tableLabel' => $label,
            'stationIds' => $stationIds,
            'waiterStaffId' => Ids::fromBinary($fresh->created_by),
            'readyAt' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
        ]));
    }

    public function tableUpdated(string $tableBin): void
    {
        $t = DB::table('dining_table')->where('id', $tableBin)->first();
        event(new TableUpdated([$this->facilityOrders(Ids::fromBinary($t->facility_unit_id))], ['table' => $this->presenter->table($t)]));
    }
}
