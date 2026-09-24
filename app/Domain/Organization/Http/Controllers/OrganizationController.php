<?php

namespace App\Domain\Organization\Http\Controllers;

use App\Domain\Organization\Models\FacilityUnit;
use App\Domain\Organization\Models\OperatingPoint;
use App\Domain\Organization\Models\Site;
use App\Domain\Organization\Services\CapabilityService;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Http\Etag;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController
{
    public function __construct(private readonly CapabilityService $capabilities) {}

    public function site(): JsonResponse
    {
        $site = Site::query()->find(Tenant::siteId()) ?? throw ApiProblem::notFound('not_found', 'No site is configured on this node.');

        return response()->json($site->toApi());
    }

    /** Facilities as a tree (roots first, children nested). */
    public function facilities(): JsonResponse
    {
        $rows = FacilityUnit::query()->where('site_id', Tenant::siteId())->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('created_at')->orderBy('code')->get();
        $caps = $this->capabilities->capabilitiesFor($rows->pluck('id')->all());

        $nodes = [];
        foreach ($rows as $f) {
            $nodes[$f->id] = $f->toApi($caps[$f->id] ?? []) + ['children' => []];
        }
        $roots = [];
        foreach ($nodes as $id => &$node) {
            if ($node['parentId'] !== null && isset($nodes[$node['parentId']])) {
                $nodes[$node['parentId']]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        return response()->json(['items' => $roots]);
    }

    public function facility(string $facilityId): JsonResponse
    {
        $f = $this->find($facilityId);

        return Etag::json($f->toApi($this->capabilities->capabilities($f->id)), $f->row_version);
    }

    public function operatingPoints(Request $request, string $facilityId): JsonResponse
    {
        $f = $this->find($facilityId);
        $page = CursorPage::paginate(OperatingPoint::query()->where('facility_unit_id', $f->id)->where('is_active', 1), $request, 'code');

        return response()->json($page->toArray(fn (OperatingPoint $p) => $p->toApi()));
    }

    public function capabilities(string $facilityId): JsonResponse
    {
        $f = $this->find($facilityId);

        return response()->json(['facilityId' => $f->id] + $this->capabilities->effective($f->id));
    }

    private function find(string $facilityId): FacilityUnit
    {
        return Ids::isUuid($facilityId)
            ? (FacilityUnit::query()->where('site_id', Tenant::siteId())->whereNull('deleted_at')->find($facilityId)
                ?? throw ApiProblem::notFound('not_found', 'Facility was not found.'))
            : throw ApiProblem::notFound('not_found', 'Facility was not found.');
    }
}
