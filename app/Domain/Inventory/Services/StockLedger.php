<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Support\MovementSpec;
use App\Domain\Inventory\Support\Qty;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * THE only writer of `stock_movement` and `stock_balance` (architecture/09 §3).
 *
 * One call = one ledger leg. In a single statement each, inside the caller's transaction:
 *   1. balance change     UPDATE stock_balance SET qty = qty + :d WHERE qty + :d >= 0     (deductions, guarded)
 *                         INSERT .. ON DUPLICATE KEY UPDATE                                (receipts / negative-allowed)
 *      The row lock taken by that statement serialises concurrent writers of the same (item, location); the loser of a
 *      race for the last unit sees 0 affected rows and gets `insufficient_stock` — never "read then write in PHP".
 *   2. ledger row         INSERT stock_movement (append-only; DB triggers forbid UPDATE/DELETE) with balance_after.
 * `dedupe_key` (UNIQUE) makes a replayed leg a no-op that returns the original movement.
 *
 * Callers posting several legs MUST use {@see postMany()} which applies them in a canonical (location, item) order
 * so concurrent multi-line documents cannot deadlock each other.
 */
class StockLedger
{
    /**
     * @return array{id: string, balanceAfter: string, replayed: bool, itemId: string, locationId: string, delta: string}
     */
    public function post(MovementSpec $m): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('StockLedger::post must run inside a DB transaction.');
        }
        $delta = Qty::normalize($m->delta);
        if (Qty::isZero($delta)) {
            throw new LogicException('Movement quantity must be non-zero.');
        }

        if ($m->dedupeKey !== null && ($existing = $this->findByDedupe($m->dedupeKey)) !== null) {
            return $existing;
        }

        $location = $this->location($m->locationId);
        $this->assertItemActive($m->itemId, $delta, $location);

        try {
            // Savepoint: if the dedupe unique key trips (racing duplicate), the balance change is rolled back with it.
            return DB::transaction(function () use ($m, $delta, $location): array {
                $item = Ids::toBinary($m->itemId);
                $loc = Ids::toBinary($m->locationId);

                if (Qty::isNegative($delta) && ! $location->allow_negative) {
                    $abs = Qty::abs($delta);
                    $affected = DB::update(
                        'UPDATE stock_balance SET qty_on_hand = qty_on_hand - ?, row_version = row_version + 1
                         WHERE item_id = ? AND location_id = ? AND qty_on_hand - ? >= 0',
                        [$abs, $item, $loc, $abs],
                    );
                    if ($affected !== 1) {
                        throw $this->insufficient($m->itemId, $m->locationId, $abs);
                    }
                } else {
                    DB::insert(
                        'INSERT INTO stock_balance (item_id, location_id, qty_on_hand) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + ?, row_version = row_version + 1',
                        [$item, $loc, $delta, $delta],
                    );
                }

                // We hold the row's X lock now, so this locking read is the exact post-change balance.
                $balance = DB::selectOne('SELECT qty_on_hand FROM stock_balance WHERE item_id = ? AND location_id = ? FOR UPDATE', [$item, $loc]);

                $id = Ids::uuid7();
                DB::table('stock_movement')->insert([
                    'id' => Ids::toBinary($id),
                    'organization_id' => Ids::toBinary($this->organizationOf($m->locationId, $location)),
                    'item_id' => $item,
                    'location_id' => $loc,
                    'counterpart_location_id' => $m->counterpartLocationId ? Ids::toBinary($m->counterpartLocationId) : null,
                    'qty_delta' => $delta,
                    'balance_after' => $balance->qty_on_hand,
                    'reason' => $m->reason,
                    'reference_type' => $m->referenceType,
                    'reference_id' => Ids::toBinary($m->referenceId),
                    'reference_line_id' => $m->referenceLineId ? Ids::toBinary($m->referenceLineId) : null,
                    'unit_cost' => $m->unitCost,
                    'note' => $m->note,
                    'dedupe_key' => $m->dedupeKey,
                    'approval_id' => $m->approvalId ? Ids::toBinary($m->approvalId) : null,
                    'actor_staff_id' => ($actor = $m->actorStaffId ?? RequestContext::staffId()) ? Ids::toBinary($actor) : null,
                    'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                ]);

                return ['id' => $id, 'balanceAfter' => $balance->qty_on_hand, 'replayed' => false, 'itemId' => $m->itemId, 'locationId' => $m->locationId, 'delta' => $delta];
            });
        } catch (QueryException $e) {
            if ($m->dedupeKey !== null && $this->isDuplicateKey($e) && ($existing = $this->findByDedupe($m->dedupeKey, true)) !== null) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Post several legs in canonical lock order (location id, item id). Legs sharing an (item, location) are fine.
     *
     * @param  list<MovementSpec>  $specs
     * @return list<array{id: string, balanceAfter: string, replayed: bool, itemId: string, locationId: string, delta: string}> in the ORIGINAL order
     */
    public function postMany(array $specs): array
    {
        $order = array_keys($specs);
        usort($order, function (int $a, int $b) use ($specs): int {
            return [$specs[$a]->locationId, $specs[$a]->itemId, $a] <=> [$specs[$b]->locationId, $specs[$b]->itemId, $b];
        });
        $out = [];
        foreach ($order as $i) {
            $out[$i] = $this->post($specs[$i]);
        }
        ksort($out);

        return array_values($out);
    }

    /** Current on-hand (locking read — call inside a transaction when you need a stable value). */
    public function onHand(string $itemId, string $locationId, bool $lock = false): string
    {
        $sql = 'SELECT qty_on_hand FROM stock_balance WHERE item_id = ? AND location_id = ?'.($lock ? ' FOR UPDATE' : '');
        $row = DB::selectOne($sql, [Ids::toBinary($itemId), Ids::toBinary($locationId)]);

        return $row ? Qty::normalize($row->qty_on_hand) : '0.0000';
    }

    public function insufficient(string $itemId, string $locationId, string $requested): ApiProblem
    {
        $available = $this->onHand($itemId, $locationId);
        $name = DB::table('inventory_item')->where('id', Ids::toBinary($itemId))->value('name') ?? $itemId;

        return ApiProblem::conflict('insufficient_stock', "Insufficient stock for {$name}: requested {$requested}, available {$available}.", [
            'meta' => ['itemId' => $itemId, 'locationId' => $locationId, 'requestedQuantity' => $requested, 'availableQuantity' => $available],
        ]);
    }

    /** @return object{id: string, organization_id: string, facility_unit_id: ?string, allow_negative: int, is_active: int} */
    public function location(string $locationId): object
    {
        $row = DB::table('stock_location')->where('id', Ids::toBinary($locationId))->first();
        if (! $row) {
            throw ApiProblem::notFound('stock_location_not_found', 'That stock location does not exist.');
        }
        if (! $row->is_active) {
            throw ApiProblem::conflict('stock_location_inactive', 'That stock location is inactive.', ['meta' => ['locationId' => $locationId]]);
        }

        return $row;
    }

    private function organizationOf(string $locationId, object $location): string
    {
        return Ids::fromBinary($location->organization_id);
    }

    private function assertItemActive(string $itemId, string $delta, object $location): void
    {
        $item = DB::table('inventory_item')->where('id', Ids::toBinary($itemId))->first(['is_active', 'organization_id']);
        if (! $item || $item->organization_id !== $location->organization_id) {
            throw ApiProblem::notFound('inventory_item_not_found', 'That inventory item does not exist.');
        }
        // Only ADDING/OUTBOUND of an inactive item is blocked for stock-in; reducing residual stock stays possible.
        if (! $item->is_active && Qty::isPositive($delta)) {
            throw ApiProblem::conflict('inventory_item_inactive', 'That inventory item is inactive.', ['meta' => ['itemId' => $itemId]]);
        }
    }

    /** @return array{id: string, balanceAfter: string, replayed: bool, itemId: string, locationId: string, delta: string}|null */
    private function findByDedupe(string $key, bool $latest = false): ?array
    {
        $q = DB::table('stock_movement')->where('dedupe_key', $key);
        // After a duplicate-key error a plain SELECT would read this transaction's (older) REPEATABLE READ snapshot and miss
        // the winner's committed row; a locking read always sees the latest committed version.
        $r = ($latest ? $q->sharedLock() : $q)->first(['id', 'balance_after', 'item_id', 'location_id', 'qty_delta']);

        return $r ? [
            'id' => Ids::fromBinary($r->id), 'balanceAfter' => $r->balance_after, 'replayed' => true,
            'itemId' => Ids::fromBinary($r->item_id), 'locationId' => Ids::fromBinary($r->location_id), 'delta' => $r->qty_delta,
        ] : null;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
