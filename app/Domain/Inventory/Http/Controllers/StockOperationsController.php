<?php

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Inventory\Services\AdjustmentService;
use App\Domain\Inventory\Services\CountService;
use App\Domain\Inventory\Services\InventoryAccess;
use App\Domain\Inventory\Services\StockDocuments;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Stock-moving endpoints. Route middleware checks the permission is held anywhere; here it is re-checked
 * against the SCOPE of the location(s) touched (facility subtree, or site for the Main Store).
 */
class StockOperationsController
{
    private const QTY = ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'];

    private const SIGNED = ['required', 'string', 'regex:/^-?\d{1,15}(\.\d{1,4})?$/'];

    public function __construct(
        private readonly StockDocuments $docs,
        private readonly AdjustmentService $adjustments,
        private readonly CountService $counts,
        private readonly InventoryAccess $access,
    ) {}

    public function purchaseReceipt(Request $request): JsonResponse
    {
        $d = $request->validate([
            'locationId' => ['required', 'uuid'], 'supplierId' => ['sometimes', 'nullable', 'uuid'], 'supplierName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'supplierInvoice' => ['sometimes', 'nullable', 'string', 'max:100'], 'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.itemId' => ['required', 'uuid'], 'lines.*.quantity' => self::QTY,
            'lines.*.unitCost' => ['sometimes', 'nullable'],
        ]);
        $this->access->authorize('inventory.purchase_receipt.create', $d['locationId']);

        return response()->json($this->docs->receive($d), 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        $d = $request->validate([
            'fromLocationId' => ['required', 'uuid'], 'toLocationId' => ['required', 'uuid'], 'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.itemId' => ['required', 'uuid'], 'lines.*.quantity' => self::QTY, 'lines.*.unitCost' => ['sometimes', 'nullable'],
        ]);
        // You may only send out of a store you control (the destination just receives).
        $this->access->authorize('inventory.transfer.create', $d['fromLocationId']);
        $this->access->locationRow($d['toLocationId']);

        return response()->json($this->docs->transfer($d), 201);
    }

    /** 201 POSTED (caller can approve) or 202 ApprovalOutcome (PENDING_APPROVAL; nothing applied yet). */
    public function adjustment(Request $request): JsonResponse
    {
        $d = $request->validate([
            'locationId' => ['required', 'uuid'], 'itemId' => ['required', 'uuid'], 'quantityDelta' => self::SIGNED,
            'reason' => ['required', Rule::in(['DAMAGE', 'THEFT', 'CORRECTION', 'EXPIRY', 'OTHER'])], 'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $this->access->authorize('inventory.adjustment.request', $d['locationId']);
        $res = $this->adjustments->request($d['locationId'], [['itemId' => $d['itemId'], 'quantityDelta' => $d['quantityDelta']]], $d['reason'], $d['note']);

        if ($res['status'] === 'POSTED') {
            return response()->json($res['movement'], 201);
        }

        return response()->json([
            'status' => 'PENDING_APPROVAL',
            'approval' => $this->approvalDto($res['approvalId'], $res['adjustmentId'], $d['locationId'], "{$d['reason']}: {$d['note']}"),
            'movement' => $res['movement'],
        ], 202);
    }

    public function decideAdjustment(Request $request, string $adjustment): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['APPROVE', 'REJECT'])], 'note' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $adj = $this->requireAdjustment($adjustment);
        $this->access->authorize(AdjustmentService::APPROVE, Ids::fromBinary($adj->location_id));

        return response()->json($this->adjustments->decide(Ids::normalize($adjustment), $d['decision'] === 'APPROVE', $d['note'] ?? null));
    }

    public function listAdjustments(Request $request): JsonResponse
    {
        $q = DB::table('stock_adjustment')->where('organization_id', Ids::toBinary((string) Tenant::organizationId()));
        if ($s = $request->query('status')) {
            $q->where('status', (string) $s);
        }
        if (($visible = $this->access->visibleLocationIds('inventory.view')) !== null) {
            $q->whereIn('location_id', array_map(Ids::toBinary(...), $visible) ?: [str_repeat("\0", 16)]);
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json([
            'items' => $page->items->map(fn ($a) => $this->adjustments->find(Ids::fromBinary($a->id)) + ['locationId' => Ids::fromBinary($a->location_id), 'reason' => $a->reason, 'note' => $a->note])->values()->all(),
            'nextCursor' => $page->nextCursor,
        ]);
    }

    public function getAdjustment(string $adjustment): JsonResponse
    {
        $adj = $this->requireAdjustment($adjustment);
        $this->access->authorize('inventory.view', Ids::fromBinary($adj->location_id));

        return response()->json($this->adjustments->find(Ids::normalize($adjustment)));
    }

    public function wastage(Request $request): JsonResponse
    {
        $d = $request->validate([
            'locationId' => ['required', 'uuid'], 'itemId' => ['required', 'uuid'], 'quantity' => self::QTY,
            'reason' => ['required', Rule::in(['SPOILAGE', 'BREAKAGE', 'PREP_WASTE', 'EXPIRY', 'OTHER'])], 'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $this->access->authorize('inventory.wastage.create', $d['locationId']);

        return response()->json($this->docs->wastage($d), 201);
    }

    public function stockReturn(Request $request): JsonResponse
    {
        $d = $request->validate([
            'locationId' => ['required', 'uuid'], 'itemId' => ['required', 'uuid'], 'quantity' => self::QTY,
            'kind' => ['required', Rule::in(['CUSTOMER', 'SUPPLIER'])], 'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $this->access->authorize('inventory.return.create', $d['locationId']);

        return response()->json($this->docs->recordReturn($d), 201);
    }

    // ---- counts ---------------------------------------------------------------------------------------------------

    public function createCount(Request $request): JsonResponse
    {
        $d = $request->validate([
            'locationId' => ['required', 'uuid'], 'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.itemId' => ['required', 'uuid'],
            'lines.*.countedQuantity' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
        ]);
        $this->access->authorize('inventory.count.create', $d['locationId']);

        return response()->json($this->counts->create($d['locationId'], $d['lines'], $d['note'] ?? null), 201);
    }

    public function postCount(string $count): JsonResponse
    {
        if (! Ids::isUuid($count)) {
            throw ApiProblem::notFound('count_not_found', 'That stock count does not exist.');
        }

        $row = DB::table('stock_count')->where('id', Ids::toBinary($count))->first(['location_id']);
        if (! $row) {
            throw ApiProblem::notFound('count_not_found', 'That stock count does not exist.');
        }
        $this->access->authorize('inventory.count.post', Ids::fromBinary($row->location_id));

        return response()->json($this->counts->post(Ids::normalize($count)));
    }

    public function getCount(string $count): JsonResponse
    {
        if (! Ids::isUuid($count)) {
            throw ApiProblem::notFound('count_not_found', 'That stock count does not exist.');
        }
        $doc = $this->counts->find(Ids::normalize($count));
        $this->access->authorize('inventory.view', $doc['locationId']);

        return response()->json($doc);
    }

    public function listCounts(Request $request): JsonResponse
    {
        $q = DB::table('stock_count')->where('organization_id', Ids::toBinary((string) Tenant::organizationId()));
        if ($s = $request->query('status')) {
            $q->where('status', (string) $s);
        }
        if (($visible = $this->access->visibleLocationIds('inventory.view')) !== null) {
            $q->whereIn('location_id', array_map(Ids::toBinary(...), $visible) ?: [str_repeat("\0", 16)]);
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json([
            'items' => $page->items->map(fn ($c) => $this->counts->find(Ids::fromBinary($c->id)))->values()->all(),
            'nextCursor' => $page->nextCursor,
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function requireAdjustment(string $id): object
    {
        $row = Ids::isUuid($id) ? DB::table('stock_adjustment')->where('id', Ids::toBinary($id))->first() : null;
        if (! $row) {
            throw ApiProblem::notFound('adjustment_not_found', 'That adjustment does not exist.');
        }

        return $row;
    }

    /** Minimal `Approval` shape (contract) — the Orders module owns the full approvals API. */
    private function approvalDto(string $approvalId, string $adjustmentId, string $locationId, string $reason): array
    {
        $loc = $this->access->locationRow($locationId);

        return [
            'id' => $approvalId, 'action' => 'inventory.adjustment', 'entityType' => 'stockAdjustment', 'entityId' => $adjustmentId,
            'facilityId' => $loc->facility_unit_id ? Ids::fromBinary($loc->facility_unit_id) : null, 'status' => 'PENDING',
            'requestedByStaffId' => RequestContext::staffId(), 'requestedAt' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'), 'reason' => $reason,
            'requiredPermission' => AdjustmentService::APPROVE, 'decidedByStaffId' => null, 'decidedAt' => null, 'decisionNote' => null,
        ];
    }
}
