<?php

namespace App\Domain\Payments\Support;

use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Read-side helpers over the payment ledger. All amounts are decimal strings (bcmath), never floats.
 *
 * IMPORTANT (isolation): these are plain (consistent-snapshot) reads. Callers that need the CURRENT truth must already
 * hold the relevant row locks (orders / payment) taken with SELECT ... FOR UPDATE, which read the latest committed data
 * and make any later snapshot read in the same transaction fresh (InnoDB REPEATABLE READ takes its snapshot at the
 * first non-locking read). The services therefore always lock first, read second.
 */
final class Ledger
{
    /** Statuses whose allocations count as money received against an order (REVERSED payments do not). */
    public const COUNTING = ['CAPTURED', 'PARTIALLY_REFUNDED', 'REFUNDED'];

    /**
     * @param  list<string>  $orderIds
     * @return array<string, string> order id => amount allocated by counting payments (4dp string; 0.0000 when none)
     */
    public static function paidByOrder(array $orderIds): array
    {
        $out = array_fill_keys(array_map(Ids::normalize(...), $orderIds), Money::zero()->amount);
        if ($orderIds === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $rows = DB::select(
            "SELECT pa.order_id, SUM(pa.amount) AS paid FROM payment_allocation pa
             JOIN payment p ON p.id = pa.payment_id
             WHERE pa.order_id IN ($in) AND p.status IN ('CAPTURED','PARTIALLY_REFUNDED','REFUNDED')
             GROUP BY pa.order_id",
            array_map(Ids::toBinary(...), $orderIds),
        );
        foreach ($rows as $r) {
            $out[Ids::fromBinary($r->order_id)] = Money::normalize((string) $r->paid);
        }

        return $out;
    }

    /** Sum of cash-in-drawer effects of a session, computed from the immutable ledger. */
    public static function cashTotals(string $sessionId): array
    {
        $sid = Ids::toBinary($sessionId);
        $cashSales = (string) DB::table('payment')->where('cash_session_id', $sid)->where('tender_type', 'CASH')
            ->whereIn('status', ['CAPTURED', 'PARTIALLY_REFUNDED', 'REFUNDED', 'REVERSED'])->sum('amount');
        $cashRefunds = (string) DB::table('refund')->where('cash_session_id', $sid)->where('tender_type', 'CASH')->sum('amount');
        $cashReversals = (string) DB::table('reversal')->where('cash_session_id', $sid)->where('tender_type', 'CASH')->sum('amount');
        $moves = DB::table('cash_movement')->where('cash_session_id', $sid)->selectRaw('kind, SUM(amount) AS total')->groupBy('kind')->pluck('total', 'kind');

        $byTender = DB::table('payment')->where('cash_session_id', $sid)->where('tender_type', '<>', 'CASH')
            ->whereIn('status', ['CAPTURED', 'PARTIALLY_REFUNDED', 'REFUNDED'])
            ->selectRaw('tender_type, SUM(amount) AS total')->groupBy('tender_type')->pluck('total', 'tender_type');

        $n = fn ($v) => Money::normalize((string) ($v ?? '0'));

        return [
            'cashSales' => $n($cashSales),
            'cashRefunds' => $n($cashRefunds),
            'cashReversals' => $n($cashReversals),
            'paidIn' => $n($moves['PAID_IN'] ?? 0),
            'paidOut' => $n($moves['PAID_OUT'] ?? 0),
            'drops' => $n($moves['DROP'] ?? 0),
            'nonCash' => $byTender->map(fn ($v) => $n($v))->all(),
        ];
    }

    /** opening + cash sales - cash refunds - cash reversals + paid in - paid out - drops */
    public static function expectedCash(string $openingFloat, array $totals): string
    {
        $v = bcadd($openingFloat, $totals['cashSales'], 4);
        $v = bcsub($v, $totals['cashRefunds'], 4);
        $v = bcsub($v, $totals['cashReversals'], 4);
        $v = bcadd($v, $totals['paidIn'], 4);
        $v = bcsub($v, $totals['paidOut'], 4);

        return bcsub($v, $totals['drops'], 4);
    }
}
