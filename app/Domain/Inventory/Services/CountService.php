<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Support\MovementSpec;
use App\Domain\Inventory\Support\Qty;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Physical stock counts. Create = a DRAFT sheet of counted quantities (expected = the balance right now, for display).
 * Post = under row locks on every counted balance, expected is re-read, variance = counted - expected, and the ledger is
 * corrected with COUNT movements so the balance ends exactly at the counted quantity. Lines whose |variance| exceeds
 * `inventory.count_variance_threshold_pct` of expected (any variance when expected is 0) are NOT posted directly: they
 * become one PENDING_APPROVAL COUNT_VARIANCE adjustment — unless the poster holds inventory.adjustment.approve.
 */
class CountService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly AdjustmentService $adjustments,
        private readonly InventoryAccess $access,
    ) {}

    /**
     * @param  list<array{itemId: string, countedQuantity: string}>  $lines
     * @return array<string, mixed>
     */
    public function create(string $locationId, array $lines, ?string $note = null, ?string $actor = null): array
    {
        $actor ??= RequestContext::staffId();
        if ($lines === []) {
            throw ApiProblem::unprocessable('validation_failed', 'A count needs at least one line.');
        }

        return DB::transaction(function () use ($locationId, $lines, $note, $actor) {
            $location = $this->ledger->location($locationId);
            $id = Ids::uuid7();
            DB::table('stock_count')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => $location->organization_id, 'location_id' => Ids::toBinary($locationId),
                'status' => 'DRAFT', 'note' => $note, 'created_by' => Ids::toBinary((string) $actor),
            ]);
            $seen = [];
            foreach ($lines as $l) {
                if (isset($seen[$l['itemId']])) {
                    throw ApiProblem::unprocessable('validation_failed', 'An item may appear only once per count.');
                }
                $seen[$l['itemId']] = true;
                $counted = Qty::normalize($l['countedQuantity']);
                if (Qty::isNegative($counted)) {
                    throw ApiProblem::unprocessable('validation_failed', 'Counted quantity cannot be negative.');
                }
                if (! DB::table('inventory_item')->where('id', Ids::toBinary($l['itemId']))->where('organization_id', $location->organization_id)->exists()) {
                    throw ApiProblem::notFound('inventory_item_not_found', 'That inventory item does not exist.');
                }
                DB::table('stock_count_line')->insert([
                    'id' => Ids::toBinary(Ids::uuid7()), 'count_id' => Ids::toBinary($id), 'item_id' => Ids::toBinary($l['itemId']),
                    'expected_quantity' => $this->ledger->onHand($l['itemId'], $locationId), 'counted_quantity' => $counted,
                ]);
            }
            Audit::record('inventory.count.create', 'StockCount', $id, null, ['locationId' => $locationId, 'lines' => count($lines)],
                facilityUnitId: $location->facility_unit_id ? Ids::fromBinary($location->facility_unit_id) : null);

            return $this->find($id);
        });
    }

    /** @return array<string, mixed> */
    public function post(string $countId, ?string $actor = null): array
    {
        $actor ??= RequestContext::staffId();

        return DB::transaction(function () use ($countId, $actor) {
            $count = DB::table('stock_count')->where('id', Ids::toBinary($countId))->lockForUpdate()->first();
            if (! $count) {
                throw ApiProblem::notFound('count_not_found', 'That stock count does not exist.');
            }
            $locationId = Ids::fromBinary($count->location_id);
            if ($count->status !== 'DRAFT') {
                throw ApiProblem::conflict('count_already_posted', 'This stock count was already posted.');
            }
            $location = $this->ledger->location($locationId);
            $facility = $location->facility_unit_id ? Ids::fromBinary($location->facility_unit_id) : null;
            $canApprove = $this->access->can(AdjustmentService::APPROVE, $location, $actor);
            $pct = (string) config('inventory.count_variance_threshold_pct', '5');

            $lines = DB::table('stock_count_line')->where('count_id', $count->id)->orderBy('item_id')->get();
            // Lock every counted balance (create the row first so a missing balance has something to lock), in item order.
            foreach ($lines as $l) {
                DB::insert('INSERT INTO stock_balance (item_id, location_id, qty_on_hand) VALUES (?, ?, 0)
                            ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand', [$l->item_id, $count->location_id]);
            }

            $direct = [];
            $pending = [];
            foreach ($lines as $l) {
                $expected = Qty::normalize(DB::selectOne('SELECT qty_on_hand FROM stock_balance WHERE item_id = ? AND location_id = ? FOR UPDATE', [$l->item_id, $count->location_id])->qty_on_hand);
                $variance = Qty::sub(Qty::normalize($l->counted_quantity), $expected);
                $status = 'NONE';
                if (! Qty::isZero($variance)) {
                    $exceeds = Qty::isZero($expected) || Qty::cmp(bcmul(Qty::abs($variance), '100', Qty::SCALE), bcmul($expected, $pct, Qty::SCALE)) > 0;
                    if ($exceeds && ! $canApprove) {
                        $status = 'PENDING_APPROVAL';
                        $pending[] = ['itemId' => Ids::fromBinary($l->item_id), 'quantityDelta' => $variance];
                    } else {
                        $status = 'POSTED';
                        $direct[] = new MovementSpec(Ids::fromBinary($l->item_id), $locationId, $variance, 'COUNT', 'stock_count', $countId, Ids::fromBinary($l->id),
                            note: $count->note, dedupeKey: 'COUNT:'.Ids::fromBinary($l->id), actorStaffId: $actor);
                    }
                }
                DB::table('stock_count_line')->where('id', $l->id)->update(['expected_quantity' => $expected, 'variance' => $variance, 'variance_status' => $status]);
            }
            $results = $this->ledger->postMany($direct);

            $adjustmentId = null;
            $approvalId = null;
            if ($pending !== []) {
                $req = $this->adjustments->request($locationId, $pending, 'COUNT', 'Stock count variance above threshold', 'COUNT_VARIANCE', $countId, $actor);
                $adjustmentId = $req['adjustmentId'];
                $approvalId = $req['approvalId'];
            }

            DB::table('stock_count')->where('id', $count->id)->update([
                'status' => 'POSTED', 'posted_by' => Ids::toBinary((string) $actor), 'posted_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'adjustment_id' => $adjustmentId ? Ids::toBinary($adjustmentId) : null, 'row_version' => $count->row_version + 1,
            ]);

            Audit::record('inventory.count.post', 'StockCount', $countId, ['status' => 'DRAFT'], [
                'status' => 'POSTED', 'locationId' => $locationId, 'postedVariances' => count($direct), 'pendingApproval' => count($pending),
                'adjustmentId' => $adjustmentId,
            ], facilityUnitId: $facility, approvalId: $approvalId);
            if ($results !== []) {
                Outbox::record('StockAdjusted', 'StockCount', $countId, [
                    'countId' => $countId, 'locationId' => $locationId, 'kind' => 'COUNT_VARIANCE',
                    'lines' => array_map(fn ($r) => ['itemId' => $r['itemId'], 'quantityDelta' => $r['delta']], $results),
                ], facilityId: $facility);
            }

            return $this->find($countId);
        });
    }

    /** @return array<string, mixed> */
    public function find(string $countId): array
    {
        $c = DB::table('stock_count')->where('id', Ids::toBinary($countId))->first();
        if (! $c) {
            throw ApiProblem::notFound('count_not_found', 'That stock count does not exist.');
        }
        $lines = DB::table('stock_count_line')->where('count_id', $c->id)->orderBy('id')->get()->map(fn ($l) => [
            'itemId' => Ids::fromBinary($l->item_id),
            'expectedQuantity' => $l->expected_quantity,
            'countedQuantity' => $l->counted_quantity,
            'variance' => $l->variance,
            'varianceStatus' => $l->variance_status,
        ])->all();
        $adj = $c->adjustment_id ? DB::table('stock_adjustment')->where('id', $c->adjustment_id)->first(['approval_id', 'status']) : null;

        return [
            'id' => $countId,
            'locationId' => Ids::fromBinary($c->location_id),
            'status' => $c->status,
            'lines' => $lines,
            'note' => $c->note,
            'createdAt' => CarbonImmutable::parse($c->created_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'postedAt' => $c->posted_at ? CarbonImmutable::parse($c->posted_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z') : null,
            'adjustmentId' => $c->adjustment_id ? Ids::fromBinary($c->adjustment_id) : null,
            'adjustmentStatus' => $adj?->status,
            'approvalId' => $adj && $adj->approval_id ? Ids::fromBinary($adj->approval_id) : null,
        ];
    }
}
