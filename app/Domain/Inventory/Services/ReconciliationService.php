<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Support\Qty;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Invariant I-5 (architecture/03 §4, 09 §3): stock_balance == SUM(stock_movement.qty_delta) per (item, location).
 * The check is ONE statement, so it reads a consistent snapshot (balance and ledger commit atomically) and is safe to
 * run while the system is selling.
 */
class ReconciliationService
{
    /** @return list<array{itemId: string, locationId: string, balance: string, ledger: string}> */
    public function drift(): array
    {
        $rows = DB::select(
            'SELECT b.item_id, b.location_id, b.qty_on_hand AS balance, COALESCE(m.s, 0) AS ledger
               FROM stock_balance b
               LEFT JOIN (SELECT item_id, location_id, SUM(qty_delta) AS s FROM stock_movement GROUP BY item_id, location_id) m
                 ON m.item_id = b.item_id AND m.location_id = b.location_id
              WHERE b.qty_on_hand <> COALESCE(m.s, 0)
             UNION ALL
             SELECT m.item_id, m.location_id, 0 AS balance, m.s AS ledger
               FROM (SELECT item_id, location_id, SUM(qty_delta) AS s FROM stock_movement GROUP BY item_id, location_id) m
               LEFT JOIN stock_balance b ON b.item_id = m.item_id AND b.location_id = m.location_id
              WHERE b.item_id IS NULL AND m.s <> 0',
        );

        return array_map(fn ($r) => [
            'itemId' => Ids::fromBinary($r->item_id), 'locationId' => Ids::fromBinary($r->location_id),
            'balance' => Qty::normalize($r->balance), 'ledger' => Qty::normalize($r->ledger),
        ], $rows);
    }

    /** Number of (item, location) pairs checked. */
    public function checkedPairs(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS c FROM (SELECT 1 FROM stock_movement GROUP BY item_id, location_id) x')->c;
    }

    /**
     * Rebuild the projection from the ledger for the drifted pairs (the ledger is the source of truth).
     *
     * @param  list<array{itemId: string, locationId: string, balance: string, ledger: string}>  $drift
     */
    public function repair(array $drift): int
    {
        $fixed = 0;
        foreach ($drift as $d) {
            DB::transaction(function () use ($d, &$fixed): void {
                $item = Ids::toBinary($d['itemId']);
                $loc = Ids::toBinary($d['locationId']);
                DB::insert('INSERT INTO stock_balance (item_id, location_id, qty_on_hand) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand', [$item, $loc]);
                $before = DB::selectOne('SELECT qty_on_hand FROM stock_balance WHERE item_id = ? AND location_id = ? FOR UPDATE', [$item, $loc])->qty_on_hand;
                $sum = DB::selectOne('SELECT COALESCE(SUM(qty_delta), 0) AS s FROM stock_movement WHERE item_id = ? AND location_id = ?', [$item, $loc])->s;
                if (Qty::cmp(Qty::normalize($before), Qty::normalize($sum)) !== 0) {
                    DB::update('UPDATE stock_balance SET qty_on_hand = ?, row_version = row_version + 1 WHERE item_id = ? AND location_id = ?', [$sum, $item, $loc]);
                    Audit::record('inventory.reconcile.repair', 'StockBalance', $d['itemId'], ['quantity' => Qty::normalize($before), 'locationId' => $d['locationId']],
                        ['quantity' => Qty::normalize($sum), 'locationId' => $d['locationId']]);
                    $fixed++;
                }
            });
        }

        return $fixed;
    }
}
