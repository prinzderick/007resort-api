<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Support\MovementDocument;
use App\Domain\Inventory\Support\MovementSpec;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Orders\Approvals\ApprovalService;
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
 *  - Otherwise a PENDING_APPROVAL `stock_adjustment` + an Orders `approval` row (action `inventory.adjustment`) are written and
 *    NOTHING touches the ledger.
 *  - A different staff member holding `inventory.adjustment.approve` decides through `POST /approvals/{id}/decision` (or the
 *    Inventory alias `POST /inventory/adjustments/{id}/decision`); {@see AdjustmentApprovalHandler} then posts the ledger legs
 *    in the same transaction (guarded: a shortfall keeps it pending with `insufficient_stock`) or closes it on reject/cancel/expiry.
 *    The approval row is locked by ApprovalService::decide, so two racing decisions cannot double-post.
 */
class AdjustmentService
{
    public const APPROVE = 'inventory.adjustment.approve';

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly InventoryAccess $access,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  list<array{itemId: string, quantityDelta: string}>  $lines
     * @return array{status: string, movement: array<string, mixed>, approvalId: ?string, adjustmentId: string, approval?: array<string, mixed>}
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
            Audit::record('inventory.adjustment.create', 'StockAdjustment', $id, null, [
                'locationId' => $locationId, 'kind' => $kind, 'reason' => $reason, 'note' => $note, 'lines' => $norm,
            ], facilityUnitId: $facility);

            if ($this->access->can(self::APPROVE, $location, $actor)) {
                $doc = $this->post($id, $actor, null);

                return ['status' => 'POSTED', 'movement' => $doc, 'approvalId' => null, 'adjustmentId' => $id];
            }

            $approval = $this->approvals->request(
                'inventory.adjustment', 'StockAdjustment', $id, $this->approvalFacility($location), self::APPROVE, "{$reason}: {$note}",
                ['adjustmentId' => $id], null, $this->summary($kind, $norm, $reason),
            );
            $approvalId = Ids::fromBinary($approval->id);
            DB::table('stock_adjustment')->where('id', Ids::toBinary($id))->update(['approval_id' => $approval->id]);

            return [
                'status' => 'PENDING_APPROVAL', 'approvalId' => $approvalId, 'adjustmentId' => $id, 'approval' => $this->approvals->present($approval),
                'movement' => MovementDocument::make($kind === 'COUNT_VARIANCE' ? 'COUNT_VARIANCE' : 'ADJUSTMENT', $id, 'PENDING_APPROVAL', $approvalId,
                    array_map(fn ($l) => ['itemId' => $l['itemId'], 'locationId' => $locationId, 'quantityDelta' => $l['quantityDelta']], $norm)),
            ];
        });
    }

    /**
     * Decide through the Orders approval workflow (permission at the approval's facility, requester != decider, row lock, audit,
     * realtime) — it calls back into {@see applyApproved()} / {@see discard()}. @return array<string, mixed> the adjustment as a StockMovement document
     */
    public function decide(string $adjustmentId, bool $approve, ?string $note = null): array
    {
        $adj = DB::table('stock_adjustment')->where('id', Ids::toBinary($adjustmentId))->first();
        if (! $adj) {
            throw ApiProblem::notFound('not_found', 'That adjustment does not exist.');
        }
        if ($adj->approval_id === null) {
            throw ApiProblem::conflict('order_state_invalid', 'This adjustment is not waiting for an approval.', ['meta' => ['status' => $adj->status]]);
        }
        $this->approvals->decide(Ids::fromBinary($adj->approval_id), $approve ? 'APPROVE' : 'REJECT', $note);

        return $this->find($adjustmentId);
    }

    /** Called by the approval handler INSIDE the decision transaction (permission + requester checks were done by ApprovalService). */
    public function applyApproved(string $adjustmentId, string $approverStaffId, string $approvalId): void
    {
        $adj = DB::table('stock_adjustment')->where('id', Ids::toBinary($adjustmentId))->lockForUpdate()->first();
        if (! $adj) {
            throw ApiProblem::notFound('not_found', 'That adjustment does not exist.');
        }
        if ($adj->status !== 'PENDING_APPROVAL') {
            throw ApiProblem::conflict('order_state_invalid', 'This adjustment has already been decided.', ['meta' => ['status' => $adj->status]]);
        }
        // ApprovalService checked the permission at the approval's (routing) facility; the authority that matters is the LOCATION's scope
        // (site-wide for the Main Store), so re-check it here — a facility supervisor cannot approve a Main Store adjustment.
        if (! $this->access->can(self::APPROVE, $this->access->locationRow(Ids::fromBinary($adj->location_id)), $approverStaffId)) {
            throw ApiProblem::permissionDenied(self::APPROVE);
        }
        $this->post($adjustmentId, $approverStaffId, $approvalId);
    }

    /** Reject / cancel / expiry of the approval: nothing was ever posted, just close the request. */
    public function discard(string $adjustmentId, string $approvalStatus): void
    {
        $adj = DB::table('stock_adjustment')->where('id', Ids::toBinary($adjustmentId))->lockForUpdate()->first();
        if (! $adj || $adj->status !== 'PENDING_APPROVAL') {
            return;
        }
        $status = $approvalStatus === 'REJECTED' ? 'REJECTED' : 'CANCELLED';
        DB::table('stock_adjustment')->where('id', $adj->id)->update(['status' => $status, 'decided_by' => RequestContext::staffId() ? Ids::toBinary((string) RequestContext::staffId()) : null,
            'decided_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'row_version' => $adj->row_version + 1]);
        $location = $this->access->locationRow(Ids::fromBinary($adj->location_id));
        Audit::record('inventory.adjustment.'.strtolower($status), 'StockAdjustment', $adjustmentId, ['status' => 'PENDING_APPROVAL'], ['status' => $status],
            facilityUnitId: $location->facility_unit_id ? Ids::fromBinary($location->facility_unit_id) : null, approvalId: $adj->approval_id ? Ids::fromBinary($adj->approval_id) : null);
    }

    /**
     * The approval workflow is facility-scoped. A facility store uses its own facility; the Main Store (site scope) uses the site's
     * top-level facility, so only staff whose approve permission covers that facility (site/org-wide holders) can decide.
     */
    private function approvalFacility(object $location): string
    {
        if ($location->facility_unit_id !== null) {
            return Ids::fromBinary($location->facility_unit_id);
        }
        $root = DB::table('facility_unit')->where('site_id', $location->site_id)->whereNull('parent_id')->orderBy('id')->first(['id']);
        if (! $root) {
            throw ApiProblem::unprocessable('validation_failed', 'The site has no facility to route the approval to.', ['locationId' => ['no approval scope']]);
        }

        return Ids::fromBinary($root->id);
    }

    /** @param list<array{itemId: string, quantityDelta: string}> $lines */
    private function summary(string $kind, array $lines, string $reason): string
    {
        $names = DB::table('inventory_item')->whereIn('id', array_map(fn ($l) => Ids::toBinary($l['itemId']), $lines))->pluck('name', 'id');
        $parts = array_map(fn ($l) => ($names[Ids::toBinary($l['itemId'])] ?? 'item').' '.(Qty::isNegative($l['quantityDelta']) ? '' : '+').rtrim(rtrim($l['quantityDelta'], '0'), '.'), array_slice($lines, 0, 3));

        return ($kind === 'COUNT_VARIANCE' ? 'Stock count variance' : "Stock adjustment ({$reason})").': '.implode(', ', $parts).(count($lines) > 3 ? ' +'.(count($lines) - 3).' more' : '');
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
