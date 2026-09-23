<?php

namespace App\Domain\Orders\Http\Controllers;

use App\Domain\Orders\Services\OrderService;
use App\Domain\Orders\Services\Presenter;
use App\Support\Api\Authz;
use App\Support\Api\Concurrency;
use App\Support\Api\Paged;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController
{
    public function __construct(private readonly OrderService $orders, private readonly Presenter $presenter) {}

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'facilityId' => ['required', 'uuid'], 'tableId' => ['nullable', 'uuid'], 'tabId' => ['nullable', 'uuid'],
            'channel' => ['nullable', 'in:DINE_IN,TAKEAWAY,ROOM,COUNTER'], 'customerName' => ['nullable', 'string', 'max:160'],
            'id' => ['nullable', 'string', $this->uuidV7()], 'clientCreatedAt' => ['nullable', 'date'],
            'lines' => ['nullable', 'array', 'max:200'], 'lines.*.productId' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'], 'lines.*.id' => ['nullable', 'string', $this->uuidV7()], 'lines.*.clientCreatedAt' => ['nullable', 'date'],
        ]);
        $r = $this->orders->create($d, $this->hash($request));

        return Concurrency::json($r['order'], $r['replayed'] ? 200 : 201, $r['order']['rowVersion']);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        $q = DB::table('order')->where('organization_id', Ids::toBinary(Tenant::organizationId()));
        if (! empty($filter['facilityId'])) {
            Authz::require('order.view', Ids::normalize($filter['facilityId']));
            $q->where('facility_unit_id', Ids::toBinary($filter['facilityId']));
        } else {
            $facilities = DB::table('facility_unit')->where('organization_id', Ids::toBinary(Tenant::organizationId()))->pluck('id')
                ->filter(fn ($b) => Authz::can('order.view', Ids::fromBinary($b)))->values()->all();
            $q->whereIn('facility_unit_id', $facilities ?: [str_repeat("\0", 16)]);
        }
        foreach (['tableId' => 'dining_table_id', 'tabId' => 'tab_id', 'createdByStaffId' => 'created_by'] as $k => $col) {
            if (! empty($filter[$k])) {
                $q->where($col, Ids::toBinary($filter[$k]));
            }
        }
        if (! empty($filter['status'])) {
            $q->whereIn('status', array_map('strtoupper', explode(',', (string) $filter['status'])));
        }
        $asc = $request->query('sort') === 'createdAt';
        $page = CursorPage::paginate($q, $request, 'id', $asc ? 'asc' : 'desc');

        return response()->json(Paged::envelope($page, fn ($o) => $this->presenter->summary($o)));
    }

    public function show(string $orderId): JsonResponse
    {
        $o = $this->orders->find($this->id($orderId));
        Authz::require('order.view', Ids::fromBinary($o->facility_unit_id));

        return Concurrency::json($this->presenter->order($o), 200, (int) $o->row_version);
    }

    public function addLine(Request $request, string $orderId): JsonResponse
    {
        $d = $request->validate([
            'productId' => ['required', 'uuid'], 'quantity' => ['required', 'integer', 'min:1', 'max:999'], 'notes' => ['nullable', 'string', 'max:255'],
            'id' => ['nullable', 'string', $this->uuidV7()], 'clientCreatedAt' => ['nullable', 'date'],
        ]);
        $r = $this->orders->addLine($this->id($orderId), $d, Concurrency::ifMatch($request), $this->hash($request));

        return Concurrency::json($r['order'], $r['replayed'] ? 200 : 201, $r['order']['rowVersion']);
    }

    public function removeLine(Request $request, string $orderId, string $lineId): JsonResponse
    {
        $o = $this->orders->removeLine($this->id($orderId), $this->id($lineId), Concurrency::ifMatch($request));

        return Concurrency::json($o, 200, $o['rowVersion']);
    }

    public function send(Request $request, string $orderId): JsonResponse
    {
        $d = $request->validate(['lineIds' => ['nullable', 'array'], 'lineIds.*' => ['uuid']]);
        $o = $this->orders->send($this->id($orderId), Concurrency::ifMatch($request), $d['lineIds'] ?? null);

        return Concurrency::json($o, 200, $o['rowVersion']);
    }

    public function serve(Request $request, string $orderId): JsonResponse
    {
        $o = $this->orders->serve($this->id($orderId), Concurrency::ifMatch($request));

        return Concurrency::json($o, 200, $o['rowVersion']);
    }

    public function void(Request $request, string $orderId): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $r = $this->orders->void($this->id($orderId), $d['reason'], Concurrency::ifMatch($request), $request->header('X-Step-Up-Token'));

        return $this->outcome($r);
    }

    public function adjust(Request $request, string $orderId, string $lineId): JsonResponse
    {
        $d = $request->validate([
            'kind' => ['required', 'in:DISCOUNT_PERCENT,DISCOUNT_AMOUNT,PRICE_OVERRIDE,COMP'], 'value' => ['required', 'string', 'max:32'], 'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $r = $this->orders->adjust($this->id($orderId), $this->id($lineId), $d, Concurrency::ifMatch($request), $request->header('X-Step-Up-Token'));

        return $this->outcome($r);
    }

    /** 200 Order when executed, 202 ApprovalOutcome when an approval was requested. @param array{done: bool, order: array<string, mixed>, approval: ?array<string, mixed>} $r */
    private function outcome(array $r): JsonResponse
    {
        if ($r['done']) {
            return Concurrency::json($r['order'], 200, $r['order']['rowVersion']);
        }

        return response()->json(['status' => 'PENDING_APPROVAL', 'approval' => $r['approval'], 'order' => $r['order']], 202, ['ETag' => Concurrency::etag($r['order']['rowVersion'])]);
    }

    private function id(string $id): string
    {
        return Ids::isUuid($id) ? Ids::normalize($id) : throw ApiProblem::notFound();
    }

    /** Client-supplied ids must be UUIDv7 (contract: 422 validation_failed otherwise). */
    private function uuidV7(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
                $fail('The :attribute must be a UUIDv7.');
            }
        };
    }

    private function hash(Request $request): string
    {
        return hash('sha256', Audit::canonicalize($request->json()->all()));
    }
}
