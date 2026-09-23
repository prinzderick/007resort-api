<?php

namespace App\Domain\Orders\Services;

use App\Support\Api\Fmt;
use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/** Row -> contract JSON for orders, tables, tabs. */
final class Presenter
{
    /** @return array<string, mixed> Order (full, with lines) */
    public function order(object $o): array
    {
        $lines = DB::table('order_line as l')->leftJoin('kds_station as s', 's.id', '=', 'l.station_id')
            ->where('l.order_id', $o->id)->whereNot('l.status', 'REMOVED')->orderBy('l.line_no')
            ->get(['l.*', 's.name as station_name']);
        $adj = $lines->isEmpty() ? collect() : DB::table('line_adjustment')->whereIn('order_line_id', $lines->pluck('id')->all())->orderBy('created_at')->get()->groupBy(fn ($a) => Ids::fromBinary($a->order_line_id));

        return [
            'id' => Ids::fromBinary($o->id),
            'number' => $o->order_number,
            'facilityId' => Ids::fromBinary($o->facility_unit_id),
            'tableId' => Fmt::u($o->dining_table_id),
            'tabId' => Fmt::u($o->tab_id),
            'customerName' => $o->customer_name,
            'channel' => $o->channel,
            'status' => $o->status,
            'lines' => $lines->map(fn ($l) => $this->line($l, $adj[Ids::fromBinary($l->id)] ?? collect()))->all(),
            'subtotal' => Fmt::money($o->subtotal),
            'discountTotal' => Fmt::money($o->discount_total),
            'taxTotal' => Fmt::money($o->tax_total),
            'total' => Fmt::money($o->total),
            'amountPaid' => Fmt::money($o->amount_paid),
            'balanceDue' => $o->status === 'VOIDED' ? '0.0000' : Money::of($o->total)->sub(Money::of($o->amount_paid))->amount,
            'currency' => $o->currency,
            'createdByStaffId' => Ids::fromBinary($o->created_by),
            'deviceId' => Fmt::u($o->device_id),
            'pendingApprovalId' => Fmt::u($o->pending_approval_id),
            'rowVersion' => (int) $o->row_version,
            'createdAt' => Fmt::ts($o->created_at),
            'updatedAt' => Fmt::ts($o->updated_at),
            'sentAt' => Fmt::ts($o->sent_at),
        ];
    }

    /** @param iterable<object> $adjustments @return array<string, mixed> */
    public function line(object $l, iterable $adjustments = []): array
    {
        return [
            'id' => Ids::fromBinary($l->id),
            'productId' => Ids::fromBinary($l->product_id),
            'name' => $l->product_name,
            'quantity' => (int) $l->quantity,
            'unitPrice' => Fmt::money($l->unit_price),
            'taxAmount' => Fmt::money($l->tax_amount),
            'lineTotal' => Fmt::money($l->line_total),
            'notes' => $l->notes,
            'status' => $l->status,
            'prepRoute' => array_filter([
                'stationId' => Fmt::u($l->station_id),
                'stationName' => $l->station_name ?? null,
                'kind' => $l->prep_route_kind,
            ], fn ($v) => $v !== null),
            'adjustments' => collect($adjustments)->map(fn ($a) => [
                'id' => Ids::fromBinary($a->id), 'kind' => $a->kind, 'value' => $a->value, 'reason' => $a->reason,
                'amount' => Fmt::money($a->amount), 'status' => $a->status, 'approvalId' => Fmt::u($a->approval_id),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> OrderSummary */
    public function summary(object $o, ?string $tableLabel = null, ?int $lineCount = null): array
    {
        $lineCount ??= (int) DB::table('order_line')->where('order_id', $o->id)->whereNotIn('status', ['REMOVED', 'VOIDED'])->count();

        return [
            'id' => Ids::fromBinary($o->id),
            'number' => $o->order_number,
            'facilityId' => Ids::fromBinary($o->facility_unit_id),
            'tableId' => Fmt::u($o->dining_table_id),
            'tableLabel' => $tableLabel ?? ($o->dining_table_id ? DB::table('dining_table')->where('id', $o->dining_table_id)->value('label') : null),
            'tabId' => Fmt::u($o->tab_id),
            'status' => $o->status,
            'total' => Fmt::money($o->total),
            'balanceDue' => $o->status === 'VOIDED' ? '0.0000' : Money::of($o->total)->sub(Money::of($o->amount_paid))->amount,
            'lineCount' => $lineCount,
            'createdAt' => Fmt::ts($o->created_at),
        ];
    }

    /** @return array<string, mixed> DiningTable */
    public function table(object $t): array
    {
        $open = DB::table('order')->where('dining_table_id', $t->id)->whereIn('status', ['DRAFT', 'SENT', 'IN_PREPARATION', 'READY', 'SERVED', 'PENDING_APPROVAL'])->orderBy('id')->pluck('id');
        $tab = DB::table('tab')->where('dining_table_id', $t->id)->where('status', 'OPEN')->orderByDesc('id')->value('id');

        return [
            'id' => Ids::fromBinary($t->id),
            'facilityId' => Ids::fromBinary($t->facility_unit_id),
            'operatingPointId' => Fmt::u($t->operating_point_id),
            'label' => $t->label,
            'seats' => (int) $t->seats,
            'status' => $t->status,
            'openOrderIds' => $open->map(fn ($b) => Ids::fromBinary($b))->all(),
            'openTabId' => Fmt::u($tab),
            'assignedStaffId' => Fmt::u($t->assigned_staff_id ?? null),
            'occupiedByStaffId' => Fmt::u($t->occupied_by_staff_id ?? null),
            'rowVersion' => (int) $t->row_version,
        ];
    }

    /** @return array<string, mixed> Tab */
    public function tab(object $t): array
    {
        $orders = DB::table('order')->where('tab_id', $t->id)->where('status', '!=', 'VOIDED')->orderBy('id')->get(['id', 'total', 'amount_paid']);
        $total = Money::zero();
        $paid = Money::zero();
        foreach ($orders as $o) {
            $total = $total->add(Money::of($o->total));
            $paid = $paid->add(Money::of($o->amount_paid));
        }

        return [
            'id' => Ids::fromBinary($t->id),
            'facilityId' => Ids::fromBinary($t->facility_unit_id),
            'tableId' => Fmt::u($t->dining_table_id),
            'customerName' => $t->customer_name,
            'status' => $t->status,
            'orderIds' => $orders->map(fn ($o) => Ids::fromBinary($o->id))->all(),
            'total' => $total->amount,
            'amountPaid' => $paid->amount,
            'balanceDue' => $total->sub($paid)->amount,
            'currency' => $t->currency,
            'openedByStaffId' => Ids::fromBinary($t->opened_by),
            'openedAt' => Fmt::ts($t->opened_at),
            'rowVersion' => (int) $t->row_version,
        ];
    }
}
