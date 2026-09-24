<?php

namespace App\Domain\Config\Http\Controllers;

use App\Domain\Config\Services\CatalogConfigService;
use App\Domain\Config\Services\CatalogCsvService;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CatalogConfigController
{
    private const MONEY = ['regex:/^\d{1,15}(\.\d{1,4})?$/'];

    public function __construct(private readonly CatalogConfigService $config, private readonly CatalogCsvService $csv) {}

    // ---- products ---------------------------------------------------------------------------------------------------------

    public function products(Request $request): JsonResponse
    {
        return response()->json($this->config->productList($request));
    }

    public function product(string $productId): JsonResponse
    {
        $p = $this->config->productView($this->id($productId));

        return Concurrency::json($p, 200, $p['rowVersion']);
    }

    public function setProductFacility(Request $request, string $productId, string $facilityId): JsonResponse
    {
        $d = $request->validate(['available' => ['required', 'boolean'], 'unavailableReason' => ['sometimes', 'nullable', 'string', 'max:24'], 'kdsStationId' => ['sometimes', 'nullable', 'uuid'],
            'sortOrder' => ['sometimes', 'integer'], 'price' => ['sometimes', 'nullable', 'string', ...self::MONEY]]);

        return response()->json($this->config->setProductFacility($this->id($productId), $this->id($facilityId), $d, Concurrency::ifMatch($request, false)));
    }

    public function removeProductFacility(string $productId, string $facilityId): JsonResponse
    {
        return response()->json($this->config->removeProductFacility($this->id($productId), $this->id($facilityId)));
    }

    // ---- price lists / prices ---------------------------------------------------------------------------------------------

    public function priceLists(): JsonResponse
    {
        $rows = DB::table('price_list')->where('organization_id', Ids::toBinary((string) Tenant::organizationId()))->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json(['items' => $rows->map(fn ($r) => $this->config->listView($r))->all(), 'nextCursor' => null]);
    }

    public function createPriceList(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'isDefault' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean']]);
        $v = $this->config->createPriceList($d);

        return Concurrency::json($v, 201, $v['rowVersion']);
    }

    public function updatePriceList(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'isDefault' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean']]);
        $v = $this->config->updatePriceList($this->id($id), $d, Concurrency::ifMatch($request));

        return Concurrency::json($v, 200, $v['rowVersion']);
    }

    public function prices(Request $request): JsonResponse
    {
        $q = DB::table('price as x')->join('product as p', 'p.id', '=', 'x.product_id')->where('p.organization_id', Ids::toBinary((string) Tenant::organizationId()))->select('x.*');
        foreach (['productId' => 'x.product_id', 'priceListId' => 'x.price_list_id', 'facilityId' => 'x.facility_unit_id'] as $param => $col) {
            if (($v = $request->query($param)) !== null && $v !== '') {
                $q->where($col, Ids::isUuid($v) ? Ids::toBinary($v) : throw ApiProblem::unprocessable('validation_failed', "{$param} must be a UUID.", [$param => ['Must be a UUID.']]));
            }
        }
        if (($a = $request->query('active')) !== null && $a !== '') {
            $q->where('x.is_active', filter_var($a, FILTER_VALIDATE_BOOL) ? 1 : 0);
        }
        $page = \App\Support\Http\CursorPage::paginate(DB::query()->fromSub($q, 'x'), $request, 'valid_from', 'desc');

        return response()->json($page->toArray(fn ($r) => $this->config->priceView($r)));
    }

    public function createPrice(Request $request): JsonResponse
    {
        $d = $request->validate(['productId' => ['required', 'uuid'], 'priceListId' => ['sometimes', 'nullable', 'uuid'], 'facilityId' => ['sometimes', 'nullable', 'uuid'],
            'amount' => ['required', 'string', ...self::MONEY], 'validFrom' => ['sometimes', 'nullable', 'date'], 'validTo' => ['sometimes', 'nullable', 'date'], 'active' => ['sometimes', 'boolean']]);
        $v = $this->config->createPrice($d);

        return Concurrency::json($v, 201, $v['rowVersion']);
    }

    public function updatePrice(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['amount' => ['sometimes', 'string', ...self::MONEY], 'validTo' => ['sometimes', 'nullable', 'date'], 'active' => ['sometimes', 'boolean']]);
        $v = $this->config->updatePrice($this->id($id), $d, Concurrency::ifMatch($request));

        return Concurrency::json($v, 200, $v['rowVersion']);
    }

    // ---- tax rates / prep routes ------------------------------------------------------------------------------------------

    public function createTaxRate(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper((string) $request->input('code', ''))]);
        $d = $request->validate(['code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,31}$/'], 'name' => ['required', 'string', 'max:80'],
            'ratePercent' => ['required', 'regex:/^\d{1,3}(\.\d{1,4})?$/', fn ($a, $v, $fail) => (float) $v <= 100 || $fail('Must be between 0 and 100.')], 'active' => ['sometimes', 'boolean']]);
        $d['ratePercent'] = (string) $d['ratePercent'];
        $v = $this->config->createTaxRate($d);

        return Concurrency::json($v, 201, $v['rowVersion']);
    }

    public function updateTaxRate(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'ratePercent' => ['sometimes', 'regex:/^\d{1,3}(\.\d{1,4})?$/', fn ($a, $v, $fail) => (float) $v <= 100 || $fail('Must be between 0 and 100.')],
            'active' => ['sometimes', 'boolean']]);
        if (isset($d['ratePercent'])) {
            $d['ratePercent'] = (string) $d['ratePercent'];
        }
        $v = $this->config->updateTaxRate($this->id($id), $d, Concurrency::ifMatch($request));

        return Concurrency::json($v, 200, $v['rowVersion']);
    }

    public function createPrepRoute(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper((string) $request->input('code', '')), 'kind' => strtoupper((string) $request->input('kind', ''))]);
        $d = $request->validate(['code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,31}$/'], 'name' => ['required', 'string', 'max:80'], 'kind' => ['required', 'in:KITCHEN,BAR,NONE']]);
        $v = $this->config->createPrepRoute($d);

        return Concurrency::json($v, 201, $v['rowVersion']);
    }

    public function updatePrepRoute(Request $request, string $id): JsonResponse
    {
        if ($request->has('kind')) {
            $request->merge(['kind' => strtoupper((string) $request->input('kind'))]);
        }
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'kind' => ['sometimes', 'in:KITCHEN,BAR,NONE']]);
        $v = $this->config->updatePrepRoute($this->id($id), $d, Concurrency::ifMatch($request));

        return Concurrency::json($v, 200, $v['rowVersion']);
    }

    public function prepRouteStations(Request $request): JsonResponse
    {
        $f = $request->query('facilityId');
        if ($f !== null && $f !== '' && ! Ids::isUuid($f)) {
            throw ApiProblem::unprocessable('validation_failed', 'facilityId must be a UUID.', ['facilityId' => ['Must be a UUID.']]);
        }

        return response()->json(['items' => $this->config->prepRouteStations($f ? Ids::normalize($f) : null), 'nextCursor' => null]);
    }

    public function setPrepRouteStation(Request $request): JsonResponse
    {
        $d = $request->validate(['facilityId' => ['required', 'uuid'], 'prepRouteId' => ['required', 'uuid'], 'kdsStationId' => ['present', 'nullable', 'uuid']]);

        return response()->json($this->config->setPrepRouteStation(array_map(fn ($v) => $v === null ? null : Ids::normalize($v), $d)));
    }

    public function categoryPrepRoute(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['prepRouteId' => ['present', 'nullable', 'uuid'], 'applyToProducts' => ['sometimes', 'boolean']]);

        return response()->json($this->config->setCategoryPrepRoute($this->id($id), $d['prepRouteId'] === null ? null : Ids::normalize($d['prepRouteId']), (bool) ($d['applyToProducts'] ?? false)));
    }

    // ---- stock links ------------------------------------------------------------------------------------------------------

    public function stockLinks(string $productId): JsonResponse
    {
        $this->config->productView($this->id($productId)); // 404 when missing

        return response()->json($this->config->stockLinks($this->id($productId)));
    }

    public function setStockLinks(Request $request, string $productId): JsonResponse
    {
        $d = $request->validate(['links' => ['present', 'array', 'max:100'], 'links.*.stockItemId' => ['required', 'uuid'], 'links.*.quantityPerUnit' => ['required', 'string', ...self::MONEY]]);

        return response()->json($this->config->setStockLinks($this->id($productId), $d['links']));
    }

    // ---- CSV ----------------------------------------------------------------------------------------------------------------

    public function exportProducts(): Response
    {
        return $this->csvResponse($this->csv->exportProducts(), 'products.csv');
    }

    public function exportPrices(): Response
    {
        return $this->csvResponse($this->csv->exportPrices(), 'prices.csv');
    }

    public function importProducts(Request $request): JsonResponse
    {
        return response()->json($this->csv->importProducts($this->body($request), ! $this->dryRun($request)));
    }

    public function importPrices(Request $request): JsonResponse
    {
        return response()->json($this->csv->importPrices($this->body($request), ! $this->dryRun($request)));
    }

    private function dryRun(Request $request): bool
    {
        $v = $request->query('dryRun');

        return $v === null || $v === '' ? true : filter_var($v, FILTER_VALIDATE_BOOL);
    }

    private function body(Request $request): string
    {
        $raw = $request->isJson() ? (string) $request->json('csv', '') : (string) $request->getContent();
        if ($raw === '' && $request->hasFile('file')) {
            $raw = (string) file_get_contents($request->file('file')->getRealPath());
        }
        if (strlen($raw) > 2_000_000) {
            throw ApiProblem::unprocessable('validation_failed', 'The file is larger than 2 MB.', ['csv' => ['Too large.']]);
        }
        if (trim($raw) === '') {
            throw ApiProblem::unprocessable('validation_failed', 'Send the CSV as the request body (text/csv) or as JSON {"csv": "..."}.', ['csv' => ['Empty body.']]);
        }

        return $raw;
    }

    private function csvResponse(string $csv, string $name): Response
    {
        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    private function id(string $id): string
    {
        return Ids::isUuid($id) ? Ids::normalize($id) : throw ApiProblem::notFound();
    }
}
