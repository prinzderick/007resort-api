<?php

namespace App\Domain\Orders\Http\Controllers;

use App\Domain\Orders\Services\Presenter;
use App\Domain\Orders\Services\TabService;
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

class TabController
{
    public function __construct(private readonly TabService $tabs, private readonly Presenter $presenter) {}

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'facilityId' => ['required', 'uuid'], 'tableId' => ['nullable', 'uuid'], 'customerName' => ['nullable', 'string', 'max:160'],
            'orderIds' => ['nullable', 'array'], 'orderIds.*' => ['uuid'], 'clientCreatedAt' => ['nullable', 'date'],
            'id' => ['nullable', 'string', function ($a, $v, $fail) {
                if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $v)) {
                    $fail('The id must be a UUIDv7.');
                }
            }],
        ]);
        $r = $this->tabs->open($d, hash('sha256', Audit::canonicalize($request->json()->all())));

        return Concurrency::json($r['tab'], $r['replayed'] ? 200 : 201, $r['tab']['rowVersion']);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        $q = DB::table('tab')->where('organization_id', Ids::toBinary(Tenant::organizationId()));
        if (! empty($filter['facilityId'])) {
            Authz::require('tab.view_own_facility', Ids::normalize($filter['facilityId']));
            $q->where('facility_unit_id', Ids::toBinary($filter['facilityId']));
        } else {
            $fs = DB::table('facility_unit')->where('organization_id', Ids::toBinary(Tenant::organizationId()))->pluck('id')
                ->filter(fn ($b) => Authz::can('tab.view_own_facility', Ids::fromBinary($b)))->values()->all();
            $q->whereIn('facility_unit_id', $fs ?: [str_repeat("\0", 16)]);
        }
        if (! empty($filter['tableId'])) {
            $q->where('dining_table_id', Ids::toBinary($filter['tableId']));
        }
        $q->whereIn('status', ! empty($filter['status']) ? array_map('strtoupper', explode(',', (string) $filter['status'])) : ['OPEN', 'SETTLING']);
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json(Paged::envelope($page, fn ($t) => $this->presenter->tab($t)));
    }

    public function show(string $tabId): JsonResponse
    {
        $t = $this->tabs->find($this->id($tabId));
        Authz::require('tab.view_own_facility', Ids::fromBinary($t->facility_unit_id));
        $p = $this->presenter->tab($t);

        return Concurrency::json($p, 200, $p['rowVersion']);
    }

    public function addOrders(Request $request, string $tabId): JsonResponse
    {
        $d = $request->validate(['orderIds' => ['required', 'array', 'min:1'], 'orderIds.*' => ['uuid']]);
        $t = $this->tabs->addOrders($this->id($tabId), array_map(Ids::normalize(...), $d['orderIds']), Concurrency::ifMatch($request));

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    private function id(string $id): string
    {
        return Ids::isUuid($id) ? Ids::normalize($id) : throw ApiProblem::notFound();
    }
}
