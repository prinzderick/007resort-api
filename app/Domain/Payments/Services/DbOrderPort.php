<?php

namespace App\Domain\Payments\Services;

use App\Domain\Orders\Services\OrderSettlementService;
use App\Domain\Payments\Contracts\OrderPort;
use App\Domain\Payments\Support\Fmt;
use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Default {@see OrderPort}: READS the Orders module's tables (locking reads for settlement, allowed as foreign-key style
 * lookups per docs/MODULES.md) and WRITES only through Orders' `OrderSettlementService` (status, amount_paid, audit, outbox,
 * realtime, table release are Orders' concern).
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
            "SELECT id, order_number, facility_unit_id, payment_facility_unit_id, status, total, subtotal, discount_total, tax_total, currency, tab_id, bill_printed_at, created_by, dining_table_id
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
                'billPrintedAt' => $r->bill_printed_at,
                'createdBy' => Fmt::uuid($r->created_by),
                'tableId' => Fmt::uuid($r->dining_table_id),
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

    public function applyPayment(string $orderId, string $delta, string $paidAfter, bool $fullyPaid, string $paymentGroupId): string
    {
        $settlement = app(OrderSettlementService::class);
        $order = $settlement->applyPayment($orderId, $delta);
        if ($fullyPaid) {
            $order = $settlement->markSettled($orderId);
        }

        return (string) $order['status'];
    }

    public function applyReversal(string $orderId, string $delta, string $paymentGroupId): string
    {
        return (string) app(OrderSettlementService::class)->applyRefund($orderId, $delta)['status'];
    }

    public function markTabSettled(string $tabId): void
    {
        app(OrderSettlementService::class)->markTabSettled($tabId);
    }
}
