<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Contracts\ApprovalRequests;
use App\Domain\Inventory\Support\MovementDocument;
use App\Domain\Inventory\Support\MovementSpec;
use App\Domain\Inventory\Support\Qty;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Manual stock adjustments and count-variance corrections (architecture/06 §3 approval workflow).
 *
 *  - The requester holds `inventory.adjustment.approve` at the location's scope  -> posted immediately (status POSTED).
 *  - Otherwise a PENDING_APPROVAL `stock_adjustment` + `approval` row is written and NOTHING touches the ledger.
 *  - A different staff member holding `inventory.adjustment.approve` decides; APPROVE posts the ledger legs (guarded:
 *    a shortfall keeps it pending with `insufficient_stock`), REJECT closes it. Decisions lock the row, so two racing
 *    decisions cannot double-post. Requester != decider (`permission_denied`).
 */
class AdjustmentService
{
    public const APPROVE = 'inventory.adjustment.approve';

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly InventoryAccess $access,
        private readonly ApprovalRequests $approvals,
    ) {}

    /**
     * @param  list<array{itemId: string, quantityDelta: string}>  $lines
     * @return array{status: string, movement: array<string, mixed>, approvalId: ?string, adjustmentId: string}
     */
    public function request(string $locationId, array $lines, string $reason, string $note, string $kind = 'ADJUSTMENT', ?string $stockCountId = null, ?string $actor = null): array
    {
        $actor ??= RequestContext::staffId();
        if ($actor === null) {
            throw ApiProblem::unauthenticated();
        }
        if ($lines === []) {
            throw ApiProblem::unprocessable('validation_failed', 'At least one line is required.');
        }

        return DB::transaction(function () use ($locationId, $lines, $reason, $note, $kind, $stockCountId, $actor) {
            $location = $this->ledger->location($locationId);
            $org = Ids::fromBinary($location->organization_id);
            $facility = $location->facility_unit_id ? Ids::fromBinary($location->facility_unit_id) : null;
            $id = Ids::uuid7();

            DB::table('stock_adjustment')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($org), 'location_id' => Ids::toBinary($locationId),
                'kind' => $kind, 'reason' => $reason, 'note' => $note, 'status' => 'PENDING_APPROVAL',
                'stock_count_id' => $stockCountId ? Ids::toBinary($stockCountId) : null, 'requested_by' => Ids::toBinary($actor),
            ]);
            $norm = [];
            $seen = [];
            foreach ($lines as $l) {
                if (isset($seen[$l['itemId']])) {
                    throw ApiProblem::unprocessable('validation_failed', 'An item may appear only once per adjustment.');
                }
                $seen[$l['itemId']] = true;
                $delta = Qty::normalize($l['quantityDelta']);
                if (Qty::isZero($delta)) {
                    throw ApiProblem::unprocessable('validation_failed', 'Adjustment quantity must be non-zero.');
                }
                $norm[] = ['itemId' => $l['itemId'], 'quantityDelta' => $delta];
                DB::table('stock_adjustment_line')->insert([
                    'id' => Ids::toBinary(Ids::uuid7()), 'adjustment_id' => Ids::toBinary($id), 'item_id' => Ids::toBinary($l['itemId']), 'qty_delta' => $delta,
                ]);
            }
            Audit::record('inventory.adjustment.request', 'StockAdjustment', $id, null, [
                'locationId' => $locationId, 'kind' => $kind, 'reason' => $reason, 'note' => $note, 'lines' => $norm,
            ], facilityUnitId: $facility);

            if ($this->access->can(self::APPROVE, $location, $actor)) {
                $doc = $this->post($id, $actor, null);

                return ['status' => 'POSTED', 'movement' => $doc, 'approvalId' => null, 'adjustmentId' => $id];
            }

            $approvalId = $this->approvals->request('inventory.adjustment', 'StockAdjustment', $id, $facility, self::APPROVE, $actor, "{$reason}: {$note}");
            DB::table('stock_adjustment')->where('id', Ids::toBinary($id))->update(['approval_id' => Ids::toBinary($approvalId)]);

            return [
                'status' => 'PENDING_APPROVAL', 'approvalId' => $approvalId, 'adjustmentId' => $id,
                'movement' => MovementDocument::make($kind === 'COUNT_VARIANCE' ? 'COUNT_VARIANCE' : 'ADJUSTMENT', $id, 'PENDING_APPROVAL', $approvalId,
                    array_map(fn ($l) => ['itemId' => $l['itemId'], 'locationId' => $locationId, 'quantityDelta' => $l['quantityDelta']], $norm)),
            ];
        });
    }

    /**
     * @return array<string, mixed> the resulting adjustment as a StockMovement document
     */
    public function decide(string $adjustmentId, bool $approve, ?string $note = null, ?string $decider = null): array
    {
        $decider ??= RequestContext::staffId();
        if ($decider === null) {
            throw ApiProblem::unauthenticated();
        }

        return DB::transaction(function () use ($adjustmentId, $approve, $note, $decider) {
            $adj = DB::table('stock_adjustment')->where('id', Ids::toBinary($adjustmentId))->lockForUpdate()->first();
            if (! $adj) {
                throw ApiProblem::notFound('adjustment_not_found', 'That adjustment does not exist.');
            }
            $location = $this->access->locationRow(Ids::fromBinary($adj->location_id));
            if (! $this->access->can(self::APPROVE, $location, $decider)) {
                throw ApiProblem::forbidden('permission_denied', 'Missing permission: '.self::APPROVE.' for this stock location.', ['permission' => self::APPROVE]);
            }
            if (Ids::fromBinary($adj->requested_by) === Ids::normalize($decider)) {
                throw ApiProblem::forbidden('permission_denied', 'You cannot decide your own adjustment request.', ['permission' => self::APPROVE]);
            }
            if ($adj->status !== 'PENDING_APPROVAL') {
                throw ApiProblem::conflict('approval_already_decided', 'This adjustment has already been decided.', ['meta' => ['status' => $adj->status]]);
            }

            $approvalId = $adj->approval_id ? Ids::fromBinary($adj->approval_id) : null;
            if ($approve) {
                $doc = $this->post($adjustmentId, $decider, $approvalId, $note);
            } else {
                DB::table('stock_adjustment')->where('id', $adj->id)->update([
                    'status' => 'REJECTED', 'decided_by' => Ids::toBinary($decider), 'decided_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                    'decision_note' => $note, 'row_version' => $adj->row_version + 1,
                ]);
                Audit::record('inventory.adjustment.reject', 'StockAdjustment', $adjustmentId, ['status' => 'PENDING_APPROVAL'], ['status' => 'REJECTED', 'note' => $note],
                    facilityUnitId: $location->facility_unit_id ? Ids::fromBinary($location->facility_unit_id) : null, approvalId: $approvalId);
                $doc = MovementDocument::make($adj->kind, $adjustmentId, 'REJECTED', $approvalId, $this->lineDocs($adjustmentId, Ids::fromBinary($adj->location_id)));
            }
            if ($approvalId !== null) {
                DB::table('approval')->where('id', Ids::toBinary($approvalId))->update([
                    'status' => $approve ? 'APPROVED' : 'REJECTED', 'approved_by' => Ids::toBinary($decider), 'decided_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
            }

            return $doc;
        });
    }

    /** Post the ledger legs of a (locked or freshly-created) adjustment and flip it to POSTED. */
    private function post(string $adjustmentId, string $actor, ?string $approvalId, ?string $decisionNote = null): array
    {
        $adj = DB::table('stock_adjustment')->where('id', Ids::toBinary($adjustmentId))->first();
        $locationId = Ids::fromBinary($adj->location_id);
        $location = $this->ledger->location($locationId);
        $facility = $location->facility_unit_id ? Ids::fromBinary($location->facility_unit_id) : null;
        $reason = $adj->kind === 'COUNT_VARIANCE' ? 'COUNT' : 'ADJUSTMENT';

        $specs = [];
        foreach (DB::table('stock_adjustment_line')->where('adjustment_id', $adj->id)->get() as $l) {
            $specs[] = new MovementSpec(Ids::fromBinary($l->item_id), $locationId, Qty::normalize($l->qty_delta), $reason, 'stock_adjustment', $adjustmentId,
                note: $adj->reason.': '.$adj->note, dedupeKey: 'ADJ:'.$adjustmentId.':'.Ids::fromBinary($l->item_id), approvalId: $approvalId, actorStaffId: $actor);
        }
        $results = $this->ledger->postMany($specs);

        DB::table('stock_adjustment')->where('id', $adj->id)->update([
            'status' => 'POSTED', 'decided_by' => Ids::toBinary($actor), 'decided_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'decision_note' => $decisionNote, 'row_version' => $adj->row_version + 1,
        ]);

        $legs = array_map(fn ($r) => ['itemId' => $r['itemId'], 'locationId' => $r['locationId'], 'quantityDelta' => $r['delta']], $results);
        Audit::record('inventory.adjustment.post', 'StockAdjustment', $adjustmentId, ['status' => $adj->status], [
            'status' => 'POSTED', 'kind' => $adj->kind, 'reason' => $adj->reason, 'lines' => $legs,
            'requestedBy' => Ids::fromBinary($adj->requested_by), 'approvedBy' => $actor,
        ], facilityUnitId: $facility, approvalId: $approvalId);
        Outbox::record('StockAdjusted', 'StockAdjustment', $adjustmentId, [
            'adjustmentId' => $adjustmentId, 'locationId' => $locationId, 'kind' => $adj->kind, 'reason' => $adj->reason,
            'lines' => $legs, 'approverStaffId' => $actor,
        ], facilityId: $facility);

        return MovementDocument::make($adj->kind === 'COUNT_VARIANCE' ? 'COUNT_VARIANCE' : 'ADJUSTMENT', $adjustmentId, 'POSTED', $approvalId, $legs);
    }

    /** @return list<array{itemId: string, locationId: string, quantityDelta: string}> */
    private function lineDocs(string $adjustmentId, string $locationId): array
    {
        return DB::table('stock_adjustment_line')->where('adjustment_id', Ids::toBinary($adjustmentId))->get()
            ->map(fn ($l) => ['itemId' => Ids::fromBinary($l->item_id), 'locationId' => $locationId, 'quantityDelta' => $l->qty_delta])->all();
    }

    /** @return array<string, mixed>|null */
    public function find(string $adjustmentId): ?array
    {
        $adj = DB::table('stock_adjustment')->where('id', Ids::toBinary($adjustmentId))->first();
        if (! $adj) {
            return null;
        }
        $approvalId = $adj->approval_id ? Ids::fromBinary($adj->approval_id) : null;

        return MovementDocument::make($adj->kind === 'COUNT_VARIANCE' ? 'COUNT_VARIANCE' : 'ADJUSTMENT', $adjustmentId, $adj->status, $approvalId,
            $this->lineDocs($adjustmentId, Ids::fromBinary($adj->location_id)), $adj->created_at);
    }
}
