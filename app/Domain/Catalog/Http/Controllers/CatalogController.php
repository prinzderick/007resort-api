<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Services\CatalogAdmin;
use App\Domain\Config\Support\ProductInput;
use App\Domain\Catalog\Services\CatalogService;
use App\Domain\Customer\Services\PublicCatalog;
use App\Domain\Customer\Support\Actor;
use App\Domain\Customer\Support\TicketCatalog;
use App\Support\Api\Authz;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Api\Paged;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogController
{
    public function __construct(private readonly CatalogService $catalog, private readonly CatalogAdmin $admin) {}

    public function categories(Request $request)
    {
        $q = DB::table('product_category')->where('organization_id', Ids::toBinary(Tenant::organizationId()));
        if (! filter_var($request->query('includeInactive'), FILTER_VALIDATE_BOOL)) {
            $q->where('is_active', 1);
        }
        if ($since = Fmt::clientTs($request->query('updatedSince'))) {
            $q->where('updated_at', '>=', $since);
        }
        $page = CursorPage::paginate($q, $request, 'sort_order', 'asc');

        return Concurrency::cacheable($request, Paged::envelope($page, fn ($r) => [
            'id' => Fmt::u($r->id), 'parentId' => Fmt::u($r->parent_id), 'name' => $r->name, 'sortOrder' => (int) $r->sort_order,
        ]));
    }

    public function products(Request $request)
    {
        $facilityId = $this->facility($request->query('facilityId'));
        $org = Tenant::organizationId();
        if (Actor::isPublic()) {
            return response()->json(app(PublicCatalog::class)->tickets($org, $facilityId));
        }
        $q = $this->catalog->productQuery($org, $facilityId);
        if (Actor::isPublic() || ! filter_var($request->query('includeInactive'), FILTER_VALIDATE_BOOL)) {
            $q->where('p.is_active', 1);
        }
        $filter = (array) $request->query('filter', []);
        if (Actor::isPublic()) {
            $filter['kind'] = 'TICKET'; // the public catalogue is tickets only (memberships have /memberships/plans)
        }
        if (! empty($filter['categoryId'])) {
            $q->where('p.category_id', Ids::toBinary($filter['categoryId']));
        }
        if (! empty($filter['kind'])) {
            $this->catalog->applyKindFilter($q, (string) $filter['kind']);
        }
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $q->where(fn ($w) => $w->where('p.name', 'like', '%'.addcslashes($term, '%_\\').'%')->orWhere('p.sku', 'like', addcslashes($term, '%_\\').'%'));
        }
        if ($since = Fmt::clientTs($request->query('updatedSince'))) {
            $q->where('p.updated_at', '>=', $since);
        }
        $page = CursorPage::paginate(DB::query()->fromSub($q, 'x'), $request);

        return $this->productPage($request, $page, $org, $facilityId);
    }

    private function productPage(Request $request, CursorPage $page, string $org, string $facilityId)
    {
        $items = $this->catalog->presentMany($page->items, $org, $facilityId);
        if (Actor::isPublic()) {
            $items = array_map(fn ($i) => TicketCatalog::publicView($i), $items);
        }
        $arr = $page->toArray();

        return Concurrency::cacheable($request, ['items' => $items, 'nextCursor' => $arr['nextCursor']]);
    }

    public function product(Request $request, string $productId): JsonResponse
    {
        $facilityId = $this->facility($request->query('facilityId'));
        $org = Tenant::organizationId();
        if (! Ids::isUuid($productId)) {
            throw ApiProblem::notFound();
        }
        $row = $this->catalog->productQuery($org, $facilityId)->where('p.id', Ids::toBinary($productId))->first();
        if (! $row) {
            throw ApiProblem::notFound('not_found', 'Product not found at this facility.');
        }
        $p = $this->catalog->presentMany([$row], $org, $facilityId)[0];

        return Concurrency::json($p, 200, $p['rowVersion']);
    }

    public function availability(Request $request): JsonResponse
    {
        $facilityId = $this->facility($request->query('facilityId'));
        $org = Tenant::organizationId();
        $q = $this->catalog->productQuery($org, $facilityId);
        $filter = (array) $request->query('filter', []);
        if (! empty($filter['productId'])) {
            $q->where('p.id', Ids::toBinary($filter['productId']));
        }
        $page = CursorPage::paginate(DB::query()->fromSub($q, 'x'), $request);
        $env = Paged::envelope($page, fn ($r) => ['productId' => Ids::fromBinary($r->id), 'facilityId' => $facilityId] + $this->catalog->availability($r, $facilityId));
        // an explicitly requested product that this facility does not sell
        if (! empty($filter['productId']) && $env['items'] === [] && Ids::isUuid($filter['productId'])) {
            $env['items'][] = ['productId' => Ids::normalize($filter['productId']), 'facilityId' => $facilityId, 'available' => false, 'reason' => 'NOT_SOLD_HERE', 'quantityOnHand' => null];
        }

        return response()->json($env);
    }

    public function setAvailability(Request $request, string $productId, string $facilityId): JsonResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:120']]);
        if (! Ids::isUuid($productId) || ! Ids::isUuid($facilityId)) {
            throw ApiProblem::notFound();
        }
        Authz::require('catalog.availability.manage', $facilityId);

        return response()->json($this->catalog->setAvailability(Ids::normalize($productId), Ids::normalize($facilityId), (bool) $data['available'], $data['reason'] ?? null));
    }

    public function prepRoutes(): JsonResponse
    {
        $rows = DB::table('prep_route')->where('organization_id', Ids::toBinary(Tenant::organizationId()))->orderBy('code')->get();

        return response()->json(['items' => $rows->map(fn ($r) => ['id' => Fmt::u($r->id), 'code' => $r->code, 'name' => $r->name, 'kind' => $r->kind])->all(), 'nextCursor' => null]);
    }

    public function taxRates(Request $request): JsonResponse
    {
        $q = DB::table('tax_rate')->where('organization_id', Ids::toBinary(Tenant::organizationId()));
        if (! filter_var($request->query('includeInactive'), FILTER_VALIDATE_BOOL)) {
            $q->where('is_active', 1);
        }
        $rows = $q->orderBy('code')->get();

        return response()->json(['items' => $rows->map(fn ($r) => ['id' => Fmt::u($r->id), 'code' => $r->code, 'name' => $r->name, 'ratePercent' => rtrim(rtrim($r->rate_percent, '0'), '.') ?: '0', 'active' => (bool) $r->is_active, 'rowVersion' => (int) $r->row_version])->all(), 'nextCursor' => null]);
    }

    // ---- admin (catalog.manage / pricing.manage) -------------------------------------------------------------

    public function createCategory(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'parentId' => ['nullable', 'uuid'], 'sortOrder' => ['nullable', 'integer']]);
        $c = $this->admin->createCategory($d);

        return Concurrency::json($c, 201, $c['rowVersion']);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'sortOrder' => ['sometimes', 'integer'], 'active' => ['sometimes', 'boolean']]);
        $c = $this->admin->updateCategory($this->id($id), $d, Concurrency::ifMatch($request, false));

        return Concurrency::json($c, 200, $c['rowVersion']);
    }

    public function createProduct(Request $request): JsonResponse
    {
        $d = $request->validate([
            'sku' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:200'],
            'categoryId' => ['required', 'uuid'], 'kind' => ['nullable', Rule::in(['GOOD', 'SERVICE', 'TICKET', 'RENTAL', 'MEMBERSHIP', 'FEE'])],
            'prepRouteId' => ['nullable', 'uuid'], 'taxRateId' => ['nullable', 'uuid'], 'taxExempt' => ['nullable', 'boolean'],
            'trackStock' => ['nullable', 'boolean'], 'imageUrl' => ['nullable', 'url', 'max:500'],
            'facilityIds' => ['nullable', 'array'], 'facilityIds.*' => ['uuid'], 'price' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            ...ProductInput::rules(),
        ]);
        $p = $this->admin->createProduct($d);

        return Concurrency::json($p, 201, $p['rowVersion']);
    }

    public function updateProduct(Request $request, string $id): JsonResponse
    {
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'], 'categoryId' => ['sometimes', 'uuid'],
            'kind' => ['sometimes', Rule::in(['GOOD', 'SERVICE', 'TICKET', 'RENTAL', 'MEMBERSHIP', 'FEE'])],
            'prepRouteId' => ['sometimes', 'nullable', 'uuid'], 'taxRateId' => ['sometimes', 'nullable', 'uuid'], 'taxExempt' => ['sometimes', 'boolean'],
            'trackStock' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'], 'imageUrl' => ['sometimes', 'nullable', 'url', 'max:500'],
            ...ProductInput::rules(),
        ]);
        $p = $this->admin->updateProduct($this->id($id), $d, Concurrency::ifMatch($request, false));

        return Concurrency::json($p, 200, $p['rowVersion']);
    }

    public function setPrice(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'], 'facilityId' => ['nullable', 'uuid']]);
        $p = $this->admin->setPrice($this->id($id), $d['amount'], isset($d['facilityId']) ? Ids::normalize($d['facilityId']) : null);

        return Concurrency::json($p, 200, $p['rowVersion']);
    }

    private function facility(mixed $facilityId): string
    {
        if (! is_string($facilityId) || ! Ids::isUuid($facilityId)) {
            throw ApiProblem::unprocessable('validation_failed', 'facilityId is required.', ['facilityId' => ['facilityId is required.']]);
        }
        $facilityId = Ids::normalize($facilityId);
        if (! DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->where('organization_id', Ids::toBinary(Tenant::organizationId()))->exists()) {
            throw ApiProblem::notFound('not_found', 'Facility not found.');
        }

        return $facilityId;
    }

    private function id(string $id): string
    {
        return Ids::isUuid($id) ? Ids::normalize($id) : throw ApiProblem::notFound();
    }
}
