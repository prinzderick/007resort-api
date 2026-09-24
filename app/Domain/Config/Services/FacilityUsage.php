<?php

namespace App\Domain\Config\Services;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * "Is this facility (or one of its capabilities) still in use?" Used to refuse deactivation / capability removal with a plain-language
 * reason instead of breaking open orders, tabs, bookings or cash drawers. History (settled orders, payments, audit) never blocks
 * deactivation: a deactivated facility keeps its rows (soft state, never a hard delete).
 */
final class FacilityUsage
{
    /** Order statuses that are not finished. */
    private const OPEN_ORDER = ['DRAFT', 'SENT', 'IN_PREPARATION', 'READY', 'SERVED', 'PENDING_APPROVAL'];

    /** capability => which usage kinds block turning it off. */
    private const CAPABILITY_BLOCKERS = [
        'POS' => ['orders', 'tabs'],
        'TABLE_SERVICE' => ['orders', 'tabs'],
        'OPEN_TAB' => ['tabs'],
        'KITCHEN_ROUTING' => ['orders'],
        'BAR_ROUTING' => ['orders'],
        'PAYMENT_ACCEPTANCE' => ['cash_sessions'],
        'BOOKING' => ['bookings'],
        'APPOINTMENTS' => ['bookings'],
        'TIME_SLOTS' => ['bookings'],
        'INVENTORY' => ['stock'],
        'TICKETING' => ['ticket_types'],
    ];

    /** @return list<array{type: string, count: int, message: string}> */
    public function forCapability(string $facilityId, string $capability): array
    {
        $kinds = self::CAPABILITY_BLOCKERS[$capability] ?? [];

        return $this->collect($facilityId, $kinds, "Capability {$capability}");
    }

    /** @return list<array{type: string, count: int, message: string}> */
    public function forDeactivation(string $facilityId): array
    {
        return $this->collect($facilityId, ['orders', 'tabs', 'cash_sessions', 'bookings', 'devices'], 'This facility');
    }

    /** @param list<string> $kinds @return list<array{type: string, count: int, message: string}> */
    private function collect(string $facilityId, array $kinds, string $subject): array
    {
        $bin = Ids::toBinary($facilityId);
        $out = [];
        $add = function (string $type, int $count, string $message) use (&$out): void {
            if ($count > 0) {
                $out[] = ['type' => $type, 'count' => $count, 'message' => $message];
            }
        };
        foreach ($kinds as $kind) {
            switch ($kind) {
                case 'orders':
                    $n = DB::table('order')->where('facility_unit_id', $bin)->whereIn('status', self::OPEN_ORDER)->count();
                    $add('open_orders', $n, "{$n} open order(s) must be settled or voided first.");
                    break;
                case 'tabs':
                    $n = DB::table('tab')->where('facility_unit_id', $bin)->whereIn('status', ['OPEN', 'SETTLING'])->count();
                    $add('open_tabs', $n, "{$n} open tab(s) must be settled or voided first.");
                    break;
                case 'cash_sessions':
                    $n = DB::table('cash_session')->where('facility_unit_id', $bin)->where('status', 'OPEN')->count();
                    $add('open_cash_sessions', $n, "{$n} cash session(s) are still open; close them first.");
                    break;
                case 'bookings':
                    $n = DB::table('booking')->where('facility_unit_id', $bin)->whereIn('status', ['HELD', 'PENDING_PAYMENT', 'CONFIRMED', 'RESCHEDULED'])
                        ->where('end_at', '>=', now('UTC')->format('Y-m-d H:i:s.u'))->count();
                    $add('active_bookings', $n, "{$n} upcoming or active booking(s) must be completed or cancelled first.");
                    break;
                case 'devices':
                    $n = DB::table('tablet_checkout')->where('facility_unit_id', $bin)->whereNull('checked_in_at')->count();
                    $add('checked_out_devices', $n, "{$n} device(s) are checked out to this facility; check them in first.");
                    break;
                case 'stock':
                    $n = DB::table('stock_balance as b')->join('stock_location as l', 'l.id', '=', 'b.location_id')
                        ->where('l.facility_unit_id', $bin)->where('b.qty_on_hand', '!=', 0)->count();
                    $add('stock_on_hand', $n, "{$n} stock line(s) still hold quantity here; transfer or write them off first.");
                    break;
                case 'ticket_types':
                    $n = DB::table('ticket_type')->where('facility_unit_id', $bin)->where('is_active', 1)->count();
                    $add('active_ticket_types', $n, "{$n} active ticket type(s) are sold here; deactivate them first.");
                    break;
            }
        }

        return $out;
    }
}
