<?php

namespace App\Domain\Config\Http\Controllers;

use App\Domain\Config\Services\FacilityLoader;
use App\Domain\Config\Services\OperatingPointAdminService;
use App\Domain\Config\Services\TableAdminService;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperatingPointAdminController
{
    public function __construct(
        private readonly OperatingPointAdminService $points,
        private readonly TableAdminService $tables,
        private readonly FacilityLoader $facilities,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('operating_point')->where('site_id', Ids::toBinary((string) Tenant::siteId()));
        if (($f = $request->query('facilityId')) !== null && $f !== '') {
            $q->where('facility_unit_id', Ids::isUuid($f) ? Ids::toBinary($f) : throw ApiProblem::unprocessable('validation_failed', 'facilityId must be a UUID.', ['facilityId' => ['Must be a UUID.']]));
        }
        if ($k = $request->query('kind')) {
            $q->where('kind', strtoupper((string) $k));
        }
        if (! filter_var($request->query('includeInactive'), FILTER_VALIDATE_BOOL)) {
            $q->where('is_active', 1);
        }

        return response()->json(CursorPage::paginate($q, $request, 'code')->toArray(fn ($r) => $this->points->present($r)));
    }

    public function store(Request $request, string $facilityId): JsonResponse
    {
        $request->merge(['code' => strtoupper((string) $request->input('code', '')), 'kind' => strtoupper((string) $request->input('kind', ''))]);
        $d = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,31}$/'], 'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:'.implode(',', OperatingPointAdminService::KINDS)], 'defaultPrepStationId' => ['sometimes', 'nullable', 'uuid'],
            'kdsStation' => ['sometimes', 'array'], 'kdsStation.kind' => ['required_with:kdsStation', 'in:KITCHEN,BAR,DISPENSE'], 'kdsStation.prepRouteId' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $p = $this->points->create($facilityId, $d);

        return Concurrency::json($p, 201, $p['rowVersion']);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'defaultPrepStationId' => ['sometimes', 'nullable', 'uuid'],
            'kdsStation' => ['sometimes', 'array'], 'kdsStation.prepRouteId' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $p = $this->points->update($id, $d, Concurrency::ifMatch($request));

        return Concurrency::json($p, 200, $p['rowVersion']);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $p = $this->points->setActive($id, false, Concurrency::ifMatch($request));

        return Concurrency::json($p, 200, $p['rowVersion']);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        $p = $this->points->setActive($id, true, Concurrency::ifMatch($request));

        return Concurrency::json($p, 200, $p['rowVersion']);
    }

    // ---- tables ----------------------------------------------------------------------------------------------------------

    public function tables(Request $request): JsonResponse
    {
        $q = DB::table('dining_table')->where('site_id', Ids::toBinary((string) Tenant::siteId()));
        foreach (['facilityId' => 'facility_unit_id', 'operatingPointId' => 'operating_point_id'] as $param => $col) {
            if (($v = $request->query($param)) !== null && $v !== '') {
                $q->where($col, Ids::isUuid($v) ? Ids::toBinary($v) : throw ApiProblem::unprocessable('validation_failed', "{$param} must be a UUID.", [$param => ['Must be a UUID.']]));
            }
        }
        if (! filter_var($request->query('includeInactive'), FILTER_VALIDATE_BOOL)) {
            $q->where('is_active', 1);
        }

        return response()->json(CursorPage::paginate($q, $request, 'sort_order')->toArray(fn ($r) => $this->tables->present($r)));
    }

    public function storeTable(Request $request, string $facilityId): JsonResponse
    {
        $d = $request->validate(['label' => ['required', 'string', 'min:1', 'max:32'], 'seats' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'operatingPointId' => ['sometimes', 'nullable', 'uuid'], 'sortOrder' => ['sometimes', 'integer']]);
        $t = $this->tables->create($facilityId, $d);

        return Concurrency::json($t, 201, $t['rowVersion']);
    }

    public function bulkTables(Request $request, string $facilityId): JsonResponse
    {
        $d = $request->validate(['prefix' => ['sometimes', 'string', 'max:24'], 'from' => ['sometimes', 'integer', 'min:0', 'max:100000'], 'to' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'padWidth' => ['sometimes', 'integer', 'min:0', 'max:6'], 'labels' => ['sometimes', 'array'], 'labels.*' => ['string', 'max:32'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:200'], 'operatingPointId' => ['sometimes', 'nullable', 'uuid']]);

        return response()->json($this->tables->bulkCreate($facilityId, $d), 201);
    }

    public function updateTable(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['label' => ['sometimes', 'string', 'min:1', 'max:32'], 'seats' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'operatingPointId' => ['sometimes', 'nullable', 'uuid'], 'sortOrder' => ['sometimes', 'integer']]);
        $t = $this->tables->update($id, $d, Concurrency::ifMatch($request));

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    public function deactivateTable(Request $request, string $id): JsonResponse
    {
        $t = $this->tables->setActive($id, false, Concurrency::ifMatch($request));

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    public function reactivateTable(Request $request, string $id): JsonResponse
    {
        $t = $this->tables->setActive($id, true, Concurrency::ifMatch($request));

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    public function merge(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['intoTableId' => ['required', 'uuid']]);
        $t = $this->tables->merge($id, $d['intoTableId'], Concurrency::ifMatch($request));

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    public function unmerge(Request $request, string $id): JsonResponse
    {
        $t = $this->tables->unmerge($id, Concurrency::ifMatch($request));

        return Concurrency::json($t, 200, $t['rowVersion']);
    }
}
