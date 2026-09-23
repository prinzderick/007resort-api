<?php

namespace App\Domain\Orders\Http\Controllers;

use App\Domain\Orders\Services\Presenter;
use App\Domain\Orders\Services\TableService;
use App\Support\Api\Authz;
use App\Support\Api\Concurrency;
use App\Support\Api\Paged;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TableController
{
    public function __construct(private readonly TableService $tables, private readonly Presenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $fid = $request->query('facilityId');
        if (! is_string($fid) || ! Ids::isUuid($fid)) {
            throw ApiProblem::unprocessable('validation_failed', 'facilityId is required.', ['facilityId' => ['facilityId is required.']]);
        }
        $fid = Ids::normalize($fid);
        if (! Authz::can('order.create', $fid) && ! Authz::can('order.view', $fid)) {
            throw ApiProblem::forbidden('permission_denied', 'Missing permission: order.view.', ['permission' => 'order.view']);
        }
        $q = DB::table('dining_table')->where('facility_unit_id', Ids::toBinary($fid))->where('is_active', 1);
        if ($s = ((array) $request->query('filter', []))['status'] ?? null) {
            $q->whereIn('status', array_map('strtoupper', explode(',', (string) $s)));
        }
        $page = CursorPage::paginate($q, $request, 'label', 'asc');

        return response()->json(Paged::envelope($page, fn ($t) => $this->presenter->table($t)));
    }

    public function show(string $tableId): JsonResponse
    {
        $t = $this->tables->find($this->id($tableId));
        $fid = Ids::fromBinary($t->facility_unit_id);
        if (! Authz::can('order.create', $fid) && ! Authz::can('order.view', $fid)) {
            throw ApiProblem::forbidden('permission_denied', 'Missing permission: order.view.', ['permission' => 'order.view']);
        }
        $p = $this->presenter->table($t);

        return Concurrency::json($p, 200, $p['rowVersion']);
    }

    public function update(Request $request, string $tableId): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:FREE,OCCUPIED,RESERVED,NEEDS_CLEANING']]);
        $t = $this->tables->setStatus($this->id($tableId), $d['status'], Concurrency::ifMatch($request));

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    public function open(string $tableId): JsonResponse
    {
        $t = $this->tables->open($this->id($tableId));

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    public function assign(Request $request, string $tableId): JsonResponse
    {
        $d = $request->validate(['staffId' => ['nullable', 'uuid']]);
        $t = $this->tables->assign($this->id($tableId), isset($d['staffId']) ? Ids::normalize($d['staffId']) : null);

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    public function transfer(Request $request, string $tableId): JsonResponse
    {
        $d = $request->validate(['toTableId' => ['required', 'uuid'], 'orderIds' => ['nullable', 'array', 'min:1'], 'orderIds.*' => ['uuid']]);
        $r = $this->tables->transfer($this->id($tableId), Ids::normalize($d['toTableId']), isset($d['orderIds']) ? array_map(Ids::normalize(...), $d['orderIds']) : null, Concurrency::ifMatch($request, false));

        return response()->json($r);
    }

    private function id(string $id): string
    {
        return Ids::isUuid($id) ? Ids::normalize($id) : throw ApiProblem::notFound();
    }
}
