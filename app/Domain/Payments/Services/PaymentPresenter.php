<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Support\Fmt;
use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Shapes payment rows into the contract's camelCase JSON (money as decimal strings, timestamps ISO-8601 UTC). */
class PaymentPresenter
{
    /**
     * @param  iterable<object>  $rows  payment rows
     * @return list<array<string, mixed>>
     */
    public function many(iterable $rows): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(fn ($r) => $r->id, $rows);
        $alloc = DB::table('payment_allocation')->whereIn('payment_id', $ids)->orderBy('id')->get(['payment_id', 'order_id', 'amount'])->groupBy(fn ($a) => Ids::fromBinary($a->payment_id));

        $coll = DB::table('payment_collection')->whereIn('payment_id', $ids)->get()->keyBy(fn ($c) => Ids::fromBinary($c->payment_id));
        $dec = $coll->isEmpty() ? collect() : DB::table('payment_collection_decision')->whereIn('payment_id', $ids)->get()->keyBy(fn ($d) => Ids::fromBinary($d->payment_id));

        $names = $this->displayNames($coll);

        return array_map(function ($r) use ($alloc, $coll, $dec, $names) {
            $id = Ids::fromBinary($r->id);

            return [
                'id' => $id,
                'groupId' => Ids::fromBinary($r->group_id),
                'facilityId' => Ids::fromBinary($r->facility_unit_id),
                'tenderType' => $r->tender_type,
                'provider' => $r->provider,
                'providerReference' => $r->provider_reference,
                'reference' => $r->reference,
                'status' => $r->status,
                'amount' => Money::normalize((string) $r->amount),
                'tendered' => $r->tendered === null ? null : Money::normalize((string) $r->tendered),
                'changeGiven' => Money::normalize((string) $r->change_given),
                'refundedAmount' => Money::normalize((string) $r->refunded_amount),
                'currency' => $r->currency,
                'allocations' => ($alloc[$id] ?? collect())->map(fn ($a) => [
                    'orderId' => Ids::fromBinary($a->order_id), 'amount' => Money::normalize((string) $a->amount),
                ])->values()->all(),
                'cashSessionId' => Fmt::uuid($r->cash_session_id),
                'receiptId' => Fmt::uuid($r->receipt_id),
                'takenByStaffId' => Fmt::uuid($r->taken_by_staff_id),
                'createdAt' => Fmt::iso($r->created_at),
                'capturedAt' => Fmt::iso($r->captured_at),
                // additive (not in contract v1): unallocated online overpayment awaiting refund
                'unallocatedAmount' => Money::normalize((string) $r->unallocated_amount),
                'collection' => isset($coll[$id]) ? $this->collection($coll[$id], $dec[$id] ?? null, $names) : null,
            ];
        }, $rows);
    }

    /**
     * Display fields for the cashier's inbox (order number, table label, waiter name, terminal label), resolved in four batched queries
     * so a list of collections needs no per-row lookups on the client. Additive to CollectionInfo.
     *
     * @param  Collection<string, object>  $collections  payment_collection rows keyed by payment uuid
     * @return array{orders: array<string, object>, tables: array<string, string>, staff: array<string, string>, terminals: array<string, string>}
     */
    private function displayNames($collections): array
    {
        if ($collections->isEmpty()) {
            return ['orders' => [], 'tables' => [], 'staff' => [], 'terminals' => []];
        }
        $orderIds = $collections->pluck('order_id')->filter()->unique()->values()->all();
        $orders = $orderIds === [] ? collect() : DB::table('order')->whereIn('id', $orderIds)->get(['id', 'order_number', 'dining_table_id'])->keyBy(fn ($o) => Ids::fromBinary($o->id));
        $tableIds = $orders->pluck('dining_table_id')->filter()->unique()->values()->all();
        $tables = $tableIds === [] ? collect() : DB::table('dining_table')->whereIn('id', $tableIds)->pluck('label', 'id')->mapWithKeys(fn ($l, $id) => [Ids::fromBinary($id) => $l]);
        $staffIds = $collections->pluck('collected_by_staff_id')->filter()->unique()->values()->all();
        $staff = $staffIds === [] ? collect() : DB::table('staff')->whereIn('id', $staffIds)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($s) => [Ids::fromBinary($s->id) => trim($s->first_name.' '.$s->last_name)]);
        $terminalIds = $collections->pluck('terminal_id')->filter()->unique()->values()->all();
        $terminals = $terminalIds === [] ? collect() : DB::table('payment_terminal')->whereIn('id', $terminalIds)->pluck('label', 'id')->mapWithKeys(fn ($l, $id) => [Ids::fromBinary($id) => $l]);

        return ['orders' => $orders->all(), 'tables' => $tables->all(), 'staff' => $staff->all(), 'terminals' => $terminals->all()];
    }

    /**
     * @param  array{orders: array<string, object>, tables: array<string, string>, staff: array<string, string>, terminals: array<string, string>}  $names
     * @return array<string, mixed> CollectionInfo (docs/WAITER_COLLECTION.md)
     */
    private function collection(object $c, ?object $d, array $names): array
    {
        $orderId = Fmt::uuid($c->order_id);
        $order = $orderId === null ? null : ($names['orders'][$orderId] ?? null);
        $tableId = $order?->dining_table_id === null ? null : Ids::fromBinary($order->dining_table_id);
        $collector = Ids::fromBinary($c->collected_by_staff_id);
        $terminalId = Fmt::uuid($c->terminal_id);

        return [
            'orderId' => $orderId,
            'orderNumber' => $order?->order_number,
            'tableLabel' => $tableId === null ? null : ($names['tables'][$tableId] ?? null),
            'collectedByName' => ($names['staff'][$collector] ?? '') !== '' ? $names['staff'][$collector] : null,
            'terminalLabel' => $terminalId === null ? null : ($names['terminals'][$terminalId] ?? null),
            'tender' => $c->tender,
            'channel' => $c->channel,
            'collectedByStaffId' => Ids::fromBinary($c->collected_by_staff_id),
            'collectedDeviceId' => Fmt::uuid($c->device_id),
            'terminalId' => Fmt::uuid($c->terminal_id),
            'approvalCode' => $c->approval_code,
            'slipReference' => $c->slip_reference,
            'last4' => $c->last4,
            'bankReference' => $c->bank_reference,
            'note' => $c->note,
            'clientCreatedAt' => Fmt::iso($c->client_created_at),
            'expiresAt' => Fmt::iso($c->expires_at),
            'autoConfirm' => (bool) $c->auto_confirm,
            'decision' => $d?->decision,
            'confirmationMode' => $d?->mode,
            'decidedByStaffId' => $d ? Fmt::uuid($d->decided_by_staff_id) : null,
            'decidedAt' => $d ? Fmt::iso($d->decided_at) : null,
            'decisionReason' => $d?->reason,
            'matchedReference' => $d?->matched_reference,
        ];
    }

    /** @return array<string, mixed> */
    public function one(object $row): array
    {
        return $this->many([$row])[0];
    }

    /** All payments of a group, oldest first. */
    public function group(string $groupId): array
    {
        return $this->many(DB::table('payment')->where('group_id', Ids::toBinary($groupId))->orderBy('id')->get()->all());
    }
}
