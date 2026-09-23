<?php

namespace App\Domain\Hospitality\Services;

use App\Support\Api\Fmt;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** PrepTicket row -> contract JSON (also the realtime `ticket` payload). */
final class TicketPresenter
{
    /** @return array<string, mixed> */
    public function ticket(object $t): array
    {
        $items = DB::table('prep_ticket_item')->where('prep_ticket_id', $t->id)->orderBy('created_at')->orderBy('id')->get();
        $waiter = DB::table('staff')->where('id', $t->waiter_staff_id)->selectRaw("TRIM(CONCAT(first_name, ' ', last_name)) as n")->value('n');

        return [
            'id' => Ids::fromBinary($t->id),
            'number' => $t->ticket_number,
            'stationId' => Ids::fromBinary($t->station_id),
            'orderId' => Ids::fromBinary($t->order_id),
            'orderNumber' => $t->order_number,
            'facilityId' => Ids::fromBinary($t->facility_unit_id),
            'tableLabel' => $t->table_label,
            'status' => $t->status,
            'items' => $items->map(fn ($i) => [
                'orderLineId' => Ids::fromBinary($i->order_line_id), 'name' => $i->name, 'quantity' => (int) $i->quantity,
                'notes' => $i->notes, 'status' => $i->status,
            ])->all(),
            'createdAt' => Fmt::ts($t->created_at),
            'acceptedAt' => Fmt::ts($t->accepted_at),
            'readyAt' => Fmt::ts($t->ready_at),
            'waiterStaffId' => Ids::fromBinary($t->waiter_staff_id),
            'waiterName' => $waiter,
            'rowVersion' => (int) $t->row_version,
        ];
    }
}
