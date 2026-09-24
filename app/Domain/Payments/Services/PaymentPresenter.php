<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Support\Fmt;
use App\Support\Ids;
use App\Support\Money\Money;
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

        return array_map(function ($r) use ($alloc, $coll, $dec) {
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
                'collection' => isset($coll[$id]) ? $this->collection($coll[$id], $dec[$id] ?? null) : null,
            ];
        }, $rows);
    }

    /** @return array<string, mixed> CollectionInfo (docs/WAITER_COLLECTION.md) */
    private function collection(object $c, ?object $d): array
    {
        return [
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
