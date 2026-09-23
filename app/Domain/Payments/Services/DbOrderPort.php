<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\OrderPort;
use App\Domain\Payments\Support\Fmt;
use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Default {@see OrderPort}: reads the Orders module's tables (locking reads for settlement) and applies the settlement
 * result to the order.
 *
 * TODO(orders-integration): the WRITE half below (applyPayment / applyReversal / markTabSettled) updates `order` / `tab`
 * directly because Orders' `OrderSettlementService::markSettled` had not landed when Payments was written. When it does,
 * replace the three write methods with calls into it (audit + outbox + table release are Orders' concern) - the
 * OrderPort interface and every caller stay unchanged.
 */
class DbOrderPort implements OrderPort
{
    public function lockTab(string $tabId): ?array
    {
        $tab = DB::selectOne('SELECT id, facility_unit_id, status FROM tab WHERE id = ? FOR UPDATE', [Ids::toBinary($tabId)]);
        if ($tab === null) {
            return null;
        }
        $orders = DB::select('SELECT id FROM `order` WHERE tab_id = ? ORDER BY id FOR UPDATE', [$tab->id]);

        return [
            'id' => Ids::fromBinary($tab->id),
            'facilityId' => Ids::fromBinary($tab->facility_unit_id),
            'status' => $tab->status,
            'orderIds' => array_map(fn ($o) => Ids::fromBinary($o->id), $orders),
        ];
    }

    public function lockOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $bins = array_map(Ids::toBinary(...), array_values(array_unique(array_map(Ids::normalize(...), $orderIds))));
        sort($bins, SORT_STRING);
        $in = implode(',', array_fill(0, count($bins), '?'));
        $rows = DB::select(
            "SELECT id, order_number, facility_unit_id, payment_facility_unit_id, status, total, subtotal, discount_total, tax_total, currency, tab_id
             FROM `order` WHERE id IN ($in) ORDER BY id FOR UPDATE",
            $bins,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[Ids::fromBinary($r->id)] = [
                'id' => Ids::fromBinary($r->id),
                'number' => $r->order_number,
                'facilityId' => Ids::fromBinary($r->facility_unit_id),
                'paymentFacilityId' => Fmt::uuid($r->payment_facility_unit_id),
                'status' => $r->status,
                'total' => Money::normalize((string) $r->total),
                'subtotal' => Money::normalize((string) $r->subtotal),
                'discountTotal' => Money::normalize((string) $r->discount_total),
                'taxTotal' => Money::normalize((string) $r->tax_total),
                'currency' => $r->currency,
                'tabId' => Fmt::uuid($r->tab_id),
            ];
        }

        return $out;
    }

    public function details(array $orderIds): array
    {
        $out = [];
        foreach ($orderIds as $id) {
            $bin = Ids::toBinary($id);
            $label = DB::table('order as o')->leftJoin('dining_table as t', 't.id', '=', 'o.dining_table_id')->where('o.id', $bin)->value('t.label');
            $lines = DB::table('order_line')->where('order_id', $bin)->whereNotIn('status', ['VOIDED', 'REMOVED'])->orderBy('line_no')
                ->get(['product_name', 'quantity', 'unit_price', 'line_total'])
                ->map(fn ($l) => [
                    'name' => $l->product_name,
                    'quantity' => (int) $l->quantity,
                    'unitPrice' => Money::normalize((string) $l->unit_price),
                    'lineTotal' => Money::normalize((string) $l->line_total),
                ])->all();
            $out[Ids::normalize($id)] = ['tableLabel' => $label, 'lineCount' => count($lines), 'lines' => $lines];
        }

        return $out;
    }

    public function applyPayment(string $orderId, string $amountPaid, bool $settled, string $paymentGroupId): void
    {
        $set = ['amount_paid' => $amountPaid, 'row_version' => DB::raw('row_version + 1')];
        if ($settled) {
            $set += ['status' => 'SETTLED', 'settled_at' => Fmt::now()];
        }
        DB::table('order')->where('id', Ids::toBinary($orderId))->update($set);
    }

    public function applyReversal(string $orderId, string $amountPaid, string $paymentGroupId): void
    {
        $bin = Ids::toBinary($orderId);
        $set = ['amount_paid' => $amountPaid, 'row_version' => DB::raw('row_version + 1')];
        if (DB::table('order')->where('id', $bin)->value('status') === 'SETTLED') {
            $set += ['status' => 'SERVED', 'settled_at' => null];
        }
        DB::table('order')->where('id', $bin)->update($set);
    }

    public function markTabSettled(string $tabId): void
    {
        DB::table('tab')->where('id', Ids::toBinary($tabId))->update(['status' => 'SETTLED', 'settled_at' => Fmt::now(), 'row_version' => DB::raw('row_version + 1')]);
    }
}
