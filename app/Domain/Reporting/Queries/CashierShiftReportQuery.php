<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Support\SourceCatalog;
use App\Support\Ids;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cashier shift reconciliation (contract CashierShiftReport). `shiftId` is resolved as a Payments cash_session id first (that is what
 * carries opening float / expected / counted cash), else as an Orders `shift` id, from which the cash session of the same staff +
 * facility overlapping the shift is used when Payments is deployed. Without Payments the report still returns the shift identity with
 * zero money and availability.payments=false.
 *
 * expectedCash (open session, computed live) = opening_float + CASH captured - CASH refunds - CASH reversals + PAID_IN - PAID_OUT - DROP.
 * A CLOSED session reports the values Payments stored at close (expected/counted/variance) unchanged.
 */
class CashierShiftReportQuery
{
    private const CAPTURED = "('CAPTURED','PARTIALLY_REFUNDED','REFUNDED','REVERSED')";

    /** @return array<string, mixed>|null null = not found */
    public function run(string $shiftId): ?array
    {
        $bin = Ids::toBinary($shiftId);
        $cs = SourceCatalog::has('cash_session', 'opening_float') ? DB::table('cash_session')->where('id', $bin)->first() : null;
        $shift = SourceCatalog::has('shift', 'staff_id') ? DB::table('shift')->where('id', $bin)->first() : null;
        if ($cs === null && $shift === null) {
            return null;
        }
        if ($cs === null && $shift !== null && SourceCatalog::has('cash_session', 'opening_float')) {
            $cs = DB::table('cash_session')->where('staff_id', $shift->staff_id)->where('facility_unit_id', $shift->facility_unit_id)
                ->where('opened_at', '<=', $shift->closed_at ?? '9999-12-31 00:00:00')->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $shift->opened_at))
                ->orderByDesc('opened_at')->first();
        }
        $src = $cs ?? $shift;
        $staff = DB::table('staff')->where('id', $src->staff_id)->first(['first_name', 'last_name']);
        $zero = Money::zero()->amount;
        $avail = SourceCatalog::availability([
            'cashSession' => ['cash_session', 'opening_float'], 'payments' => ['payment', 'cash_session_id', 'tender_type', 'status', 'amount'],
            'refunds' => ['refund', 'cash_session_id', 'amount'], 'cashMovements' => ['cash_movement', 'cash_session_id', 'kind', 'amount'], 'voids' => ['line_void', 'voided_by'],
        ]);
        $fmt = fn ($v) => $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');

        $byTender = [];
        $paymentIds = [];
        $cashIn = $zero;
        $refunds = $zero;
        $cashRefunds = $zero;
        if ($cs !== null && $avail['payments']) {
            foreach (DB::select('SELECT tender_type, COUNT(*) c, COALESCE(SUM(amount),0) a FROM payment WHERE cash_session_id = ? AND status IN '.self::CAPTURED.' GROUP BY tender_type ORDER BY tender_type', [$cs->id]) as $t) {
                $byTender[] = ['tenderType' => $t->tender_type, 'amount' => Money::normalize($t->a), 'count' => (int) $t->c];
                if ($t->tender_type === 'CASH') {
                    $cashIn = Money::normalize($t->a);
                }
            }
            $paymentIds = array_map(fn ($r) => Ids::fromBinary($r->id), DB::select('SELECT id FROM payment WHERE cash_session_id = ? AND status IN '.self::CAPTURED.' ORDER BY created_at, id', [$cs->id]));
        }
        if ($cs !== null && $avail['refunds']) {
            foreach (DB::select('SELECT tender_type, COALESCE(SUM(amount),0) a FROM refund WHERE cash_session_id = ? GROUP BY tender_type', [$cs->id]) as $r) {
                $refunds = Money::of($refunds)->add(Money::of($r->a))->amount;
                if ($r->tender_type === 'CASH') {
                    $cashRefunds = Money::normalize($r->a);
                }
            }
        }
        $movement = $zero;
        if ($cs !== null && $avail['cashMovements']) {
            foreach (DB::select('SELECT kind, COALESCE(SUM(amount),0) a FROM cash_movement WHERE cash_session_id = ? GROUP BY kind', [$cs->id]) as $m) {
                $movement = $m->kind === 'PAID_IN' ? Money::of($movement)->add(Money::of($m->a))->amount : Money::of($movement)->sub(Money::of($m->a))->amount;
            }
        }
        $voids = 0;
        if ($avail['voids']) {
            $q = DB::table('line_void')->where('voided_by', $src->staff_id)->where('created_at', '>=', $src->opened_at);
            if ($src->closed_at !== null) {
                $q->where('created_at', '<=', $src->closed_at);
            }
            $voids = $q->count();
        }

        $opening = $cs !== null ? Money::normalize($cs->opening_float) : $zero;
        $expected = $cs !== null && $cs->expected_cash !== null
            ? Money::normalize($cs->expected_cash)
            : Money::of($opening)->add(Money::of($cashIn))->sub(Money::of($cashRefunds))->add(Money::of($movement))->amount;

        return [
            'shiftId' => $shiftId, 'cashSessionId' => $cs ? Ids::fromBinary($cs->id) : null, 'staffId' => Ids::fromBinary($src->staff_id),
            'staffName' => $staff ? trim($staff->first_name.' '.$staff->last_name) : null, 'facilityId' => Ids::fromBinary($src->facility_unit_id),
            'status' => $src->status, 'openedAt' => $fmt($src->opened_at), 'closedAt' => $fmt($src->closed_at),
            'openingFloat' => $opening, 'byTender' => $byTender, 'expectedCash' => $cs === null ? $zero : $expected,
            'countedCash' => $cs?->counted_cash === null ? null : Money::normalize($cs->counted_cash),
            'variance' => $cs?->variance === null ? null : Money::normalize($cs->variance),
            'refunds' => $refunds, 'voids' => $voids, 'paymentIds' => $paymentIds, 'currency' => 'NGN', 'availability' => $avail,
        ];
    }
}
