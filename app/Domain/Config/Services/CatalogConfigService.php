<?php

namespace App\Domain\Config\Services;

use App\Domain\Catalog\Services\CatalogAdmin;
use App\Domain\Catalog\Services\CatalogService;
use App\Domain\Config\Support\ConfigChange;
use App\Domain\Config\Support\ConfigIds;
use App\Domain\Config\Support\ConfigVersion;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue configuration beyond the basic product/category CRUD (Catalog module): per-facility selling, price lists and
 * effective-dated prices, tax rates, prep routes and their station mapping, stock links.
 */
class CatalogConfigService
{
    public function __construct(private readonly CatalogAdmin $admin) {}

    private function org(): string
    {
        return Tenant::organizationId() ?? throw ApiProblem::notFound('not_found', 'No organization is configured on this node.');
    }

    private function orgBin(): string
    {
        return Ids::toBinary($this->org());
    }

    // ---- products (admin views) -------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function productView(string $id): array
    {
        $r = DB::table('product as p')->leftJoin('prep_route as pr', 'pr.id', '=', 'p.prep_route_id')->leftJoin('product_category as c', 'c.id', '=', 'p.category_id')
            ->where('p.id', Ids::toBinary($id))->where('p.organization_id', $this->orgBin())->whereNull('p.deleted_at')->first(['p.*', 'pr.kind as route_kind', 'c.name as category_name'])
            ?? throw ApiProblem::notFound('not_found', 'Product was not found.');
        $prices = DB::table('price')->where('product_id', $r->id)->orderByDesc('valid_from')->get();
        $facilities = DB::table('product_facility as pf')->join('facility_unit as f', 'f.id', '=', 'pf.facility_unit_id')->where('pf.product_id', $r->id)->orderBy('f.code')
            ->get(['pf.*', 'f.name as facility_name', 'f.code as facility_code']);
        $override = fn ($fid) => $prices->first(fn ($p) => $p->facility_unit_id === $fid && $p->is_active && $p->valid_to === null);

        return [
            'id' => $id, 'sku' => $r->sku, 'name' => $r->name, 'description' => $r->description, 'barcode' => $r->barcode, 'categoryId' => Fmt::u($r->category_id), 'categoryName' => $r->category_name,
            'kind' => CatalogService::contractKind($r->kind, $r->route_kind ?? 'NONE'), 'commercialKind' => $r->kind, 'taxRateId' => Fmt::u($r->tax_rate_id), 'taxExempt' => (bool) $r->tax_exempt,
            'prepRouteId' => Fmt::u($r->prep_route_id), 'trackStock' => (bool) $r->track_stock, 'imageUrl' => $r->image_url, 'modifiers' => $r->modifiers === null ? null : json_decode($r->modifiers, true),
            'active' => (bool) $r->is_active,
            'prices' => $prices->map(fn ($p) => $this->priceView($p))->all(),
            'facilities' => $facilities->map(fn ($f) => [
                'facilityId' => Ids::fromBinary($f->facility_unit_id), 'facilityCode' => $f->facility_code, 'facilityName' => $f->facility_name, 'available' => (bool) $f->is_available,
                'unavailableReason' => $f->unavailable_reason, 'kdsStationId' => Fmt::u($f->kds_station_id), 'sortOrder' => (int) $f->sort_order,
                'priceOverride' => ($o = $override($f->facility_unit_id)) ? Money::normalize($o->amount) : null,
            ])->all(),
            'stockLinks' => $this->stockLinks($id)['links'], 'rowVersion' => (int) $r->row_version,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, nextCursor: ?string} */
    public function productList(Request $request): array
    {
        $q = DB::table('product as p')->leftJoin('product_category as c', 'c.id', '=', 'p.category_id')->where('p.organization_id', $this->orgBin())->whereNull('p.deleted_at')
            ->select(['p.*', 'c.name as category_name']);
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('p.sku', 'like', $like)->orWhere('p.barcode', 'like', $like));
        }
        if (($c = $request->query('categoryId')) !== null && $c !== '') {
            $q->where('p.category_id', Ids::isUuid($c) ? Ids::toBinary($c) : throw ApiProblem::unprocessable('validation_failed', 'categoryId must be a UUID.', ['categoryId' => ['Must be a UUID.']]));
        }
        if (($a = $request->query('active')) !== null && $a !== '') {
            $q->where('p.is_active', filter_var($a, FILTER_VALIDATE_BOOL) ? 1 : 0);
        }
        $page = CursorPage::paginate(DB::query()->fromSub($q, 'x'), $request, 'name');
        $ids = $page->items->pluck('id')->all();
        $counts = $ids === [] ? collect() : DB::table('product_facility')->whereIn('product_id', $ids)->selectRaw('product_id, COUNT(*) n')->groupBy('product_id')->pluck('n', 'product_id');
        $linked = $ids === [] ? collect() : DB::table('product_stock_link')->whereIn('product_id', $ids)->distinct()->pluck('product_id')->flip();
        $list = DB::table('price_list')->where('organization_id', $this->orgBin())->where('is_default', 1)->where('is_active', 1)->value('id');
        $now = Fmt::now();
        $prices = ($ids === [] || $list === null) ? collect() : DB::table('price')->whereIn('product_id', $ids)->where('price_list_id', $list)->whereNull('facility_unit_id')->where('is_active', 1)
            ->where('valid_from', '<=', $now)->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>', $now))->orderBy('valid_from')->pluck('amount', 'product_id');

        return $page->toArray(fn ($r) => [
            'id' => Ids::fromBinary($r->id), 'sku' => $r->sku, 'name' => $r->name, 'barcode' => $r->barcode, 'categoryId' => Fmt::u($r->category_id), 'categoryName' => $r->category_name,
            'commercialKind' => $r->kind, 'active' => (bool) $r->is_active, 'taxRateId' => Fmt::u($r->tax_rate_id), 'prepRouteId' => Fmt::u($r->prep_route_id), 'trackStock' => (bool) $r->track_stock,
            'defaultPrice' => isset($prices[$r->id]) ? Money::normalize($prices[$r->id]) : null, 'facilityCount' => (int) ($counts[$r->id] ?? 0), 'hasStockLink' => isset($linked[$r->id]), 'rowVersion' => (int) $r->row_version,
        ]);
    }

    /** Sell (or stop selling) a product at a facility, with station override and price override. @param array<string, mixed> $in @return array<string, mixed> */
    public function setProductFacility(string $productId, string $facilityId, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($productId, $facilityId, $in, $ifMatch): array {
            $p = $this->lockProduct($productId);
            $f = DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->where('organization_id', $this->orgBin())->whereNull('deleted_at')->first()
                ?? throw ApiProblem::notFound('not_found', 'Facility was not found.');
            if ($in['available'] === false) {
                $reason = $in['unavailableReason'] ?? 'MANUALLY_DISABLED';
                if (! in_array($reason, ['OUT_OF_STOCK', 'NOT_SOLD_HERE', 'INACTIVE', 'MANUALLY_DISABLED'], true)) {
                    throw ApiProblem::unprocessable('validation_failed', 'Unknown reason.', ['unavailableReason' => ['One of OUT_OF_STOCK, NOT_SOLD_HERE, INACTIVE, MANUALLY_DISABLED.']]);
                }
            }
            $station = null;
            if (array_key_exists('kdsStationId', $in) && $in['kdsStationId'] !== null) {
                $st = DB::table('kds_station')->where('id', Ids::toBinary($in['kdsStationId']))->where('organization_id', $this->orgBin())->where('is_active', 1)->first();
                if ($st === null) {
                    throw ApiProblem::unprocessable('validation_failed', 'Unknown or inactive KDS station.', ['kdsStationId' => ['Choose an active KDS station.']]);
                }
                $station = $st->id;
            }
            $pf = DB::table('product_facility')->where('product_id', $p->id)->where('facility_unit_id', $f->id)->lockForUpdate()->first();
            if ($ifMatch !== null && $pf !== null) {
                Concurrency::assertVersion((int) $pf->row_version, $ifMatch, 'product at facility');
            }
            $row = ['is_available' => $in['available'] ? 1 : 0, 'unavailable_reason' => $in['available'] ? null : ($in['unavailableReason'] ?? 'MANUALLY_DISABLED')];
            if (array_key_exists('kdsStationId', $in)) {
                $row['kds_station_id'] = $station;
            }
            if (array_key_exists('sortOrder', $in)) {
                $row['sort_order'] = (int) $in['sortOrder'];
            }
            $old = $pf === null ? null : ['available' => (bool) $pf->is_available, 'kdsStationId' => Fmt::u($pf->kds_station_id), 'sortOrder' => (int) $pf->sort_order];
            if ($pf === null) {
                DB::table('product_facility')->insert(['product_id' => $p->id, 'facility_unit_id' => $f->id, 'row_version' => 1] + $row);
                $version = 1;
            } else {
                $version = (int) $pf->row_version + 1;
                DB::table('product_facility')->where('product_id', $p->id)->where('facility_unit_id', $f->id)->update($row + ['row_version' => $version, 'updated_at' => Fmt::now()]);
            }
            $priceChanged = false;
            if (array_key_exists('price', $in)) {
                $priceChanged = $this->setFacilityPrice($productId, $facilityId, $in['price']);
            }
            DB::table('product')->where('id', $p->id)->update(['updated_at' => Fmt::now()]);
            $now = DB::table('product_facility')->where('product_id', $p->id)->where('facility_unit_id', $f->id)->first();
            $new = ['available' => (bool) $now->is_available, 'reason' => $now->unavailable_reason, 'kdsStationId' => Fmt::u($now->kds_station_id), 'sortOrder' => (int) $now->sort_order, 'priceChanged' => $priceChanged];
            Audit::record('config.catalog.product_facility.set', 'Product', $productId, $old, $new, facilityUnitId: $facilityId);
            Outbox::record('ConfigurationUpdated', 'ProductFacility', ConfigIds::pair($productId, $facilityId),
                ['domain' => 'productFacility', 'changes' => ['productId' => $productId, 'facilityId' => $facilityId] + $new], ConfigVersion::next(ConfigIds::pair($productId, $facilityId)), facilityId: $facilityId);

            return $this->productView($productId);
        });
    }

    public function removeProductFacility(string $productId, string $facilityId): array
    {
        return DB::transaction(function () use ($productId, $facilityId): array {
            $p = $this->lockProduct($productId);
            $pf = DB::table('product_facility')->where('product_id', $p->id)->where('facility_unit_id', Ids::toBinary($facilityId))->lockForUpdate()->first();
            if ($pf === null) {
                return $this->productView($productId);
            }
            // history is safe: order lines snapshot the product; only the "sold here" link goes.
            DB::table('product_facility')->where('product_id', $p->id)->where('facility_unit_id', $pf->facility_unit_id)->update(['is_available' => 0, 'unavailable_reason' => 'NOT_SOLD_HERE', 'row_version' => $pf->row_version + 1]);
            DB::table('product')->where('id', $p->id)->update(['updated_at' => Fmt::now()]);
            $this->setFacilityPrice($productId, $facilityId, null);
            Audit::record('config.catalog.product_facility.remove', 'Product', $productId, ['available' => (bool) $pf->is_available], ['available' => false, 'reason' => 'NOT_SOLD_HERE'], facilityUnitId: $facilityId);
            Outbox::record('ConfigurationUpdated', 'ProductFacility', ConfigIds::pair($productId, $facilityId),
                ['domain' => 'productFacility', 'changes' => ['productId' => $productId, 'facilityId' => $facilityId, 'available' => false, 'reason' => 'NOT_SOLD_HERE']], ConfigVersion::next(ConfigIds::pair($productId, $facilityId)), facilityId: $facilityId);

            return $this->productView($productId);
        });
    }

    /** Facility-specific price on the default list: null ends it. @return bool changed */
    private function setFacilityPrice(string $productId, string $facilityId, ?string $amount): bool
    {
        if ($amount !== null) {
            if (! Money::isValid($amount) || str_starts_with(trim($amount), '-')) {
                throw ApiProblem::unprocessable('validation_failed', 'price must be a non-negative decimal string.', ['price' => ['Must be a non-negative decimal string.']]);
            }
            $cur = DB::table('price as p')->join('price_list as pl', 'pl.id', '=', 'p.price_list_id')->where('p.product_id', Ids::toBinary($productId))->where('pl.is_default', 1)
                ->where('p.facility_unit_id', Ids::toBinary($facilityId))->where('p.is_active', 1)->whereNull('p.valid_to')->value('p.amount');
            if ($cur !== null && Money::normalize($cur) === Money::normalize($amount)) {
                return false;
            }
            $this->admin->setPrice($productId, $amount, $facilityId);

            return true;
        }
        $rows = DB::table('price')->where('product_id', Ids::toBinary($productId))->where('facility_unit_id', Ids::toBinary($facilityId))->where('is_active', 1)->get();
        foreach ($rows as $r) {
            DB::table('price')->where('id', $r->id)->update(['is_active' => 0, 'valid_to' => Fmt::now(), 'row_version' => $r->row_version + 1]);
            Outbox::record('ConfigurationUpdated', 'Price', Ids::fromBinary($r->id), ['domain' => 'price', 'changes' => ['active' => false]], (int) $r->row_version + 1, facilityId: $facilityId);
        }
        if ($rows->isNotEmpty()) {
            Audit::record('config.catalog.price.clear_override', 'Product', $productId, ['facilityId' => $facilityId], ['cleared' => $rows->count()], facilityUnitId: $facilityId);
        }

        return $rows->isNotEmpty();
    }

    // ---- price lists & prices ----------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function listView(object $r): array
    {
        return ['id' => Ids::fromBinary($r->id), 'name' => $r->name, 'currency' => $r->currency, 'isDefault' => (bool) $r->is_default, 'active' => (bool) $r->is_active, 'rowVersion' => (int) $r->row_version];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function createPriceList(array $in): array
    {
        return DB::transaction(function () use ($in): array {
            $id = Ids::uuid7();
            if (! empty($in['isDefault'])) {
                DB::table('price_list')->where('organization_id', $this->orgBin())->where('is_default', 1)->lockForUpdate()->update(['is_default' => 0]);
            }
            DB::table('price_list')->insert(['id' => Ids::toBinary($id), 'organization_id' => $this->orgBin(), 'name' => $in['name'], 'currency' => 'NGN', 'is_default' => ! empty($in['isDefault']) ? 1 : 0, 'is_active' => ($in['active'] ?? true) ? 1 : 0]);
            $v = $this->listView(DB::table('price_list')->where('id', Ids::toBinary($id))->first());
            ConfigChange::record('config.catalog.price_list.create', 'PriceList', $id, null, $v, 'priceList', $v + ['organizationId' => $this->org()], 1);

            return $v;
        });
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function updatePriceList(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch): array {
            $r = DB::table('price_list')->where('id', Ids::toBinary($id))->where('organization_id', $this->orgBin())->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'Price list was not found.');
            Concurrency::assertVersion((int) $r->row_version, $ifMatch, 'price list');
            $old = $this->listView($r);
            $set = [];
            if (isset($in['name'])) {
                $set['name'] = $in['name'];
            }
            if (array_key_exists('active', $in)) {
                if (! $in['active'] && $r->is_default) {
                    throw ApiProblem::conflict('default_price_list_required', 'The default price list cannot be deactivated. Make another list the default first.');
                }
                $set['is_active'] = $in['active'] ? 1 : 0;
            }
            if (isset($in['isDefault'])) {
                if ($in['isDefault']) {
                    DB::table('price_list')->where('organization_id', $this->orgBin())->where('is_default', 1)->where('id', '!=', $r->id)->update(['is_default' => 0]);
                    $set['is_default'] = 1;
                    $set['is_active'] = 1;
                } elseif ($r->is_default) {
                    throw ApiProblem::conflict('default_price_list_required', 'Exactly one price list must be the default: make another list the default instead.');
                }
            }
            if ($set === []) {
                return $old;
            }
            DB::table('price_list')->where('id', $r->id)->update($set + ['row_version' => $r->row_version + 1]);
            $new = $this->listView(DB::table('price_list')->where('id', $r->id)->first());
            ConfigChange::record('config.catalog.price_list.update', 'PriceList', $id, $old, $new, 'priceList', $new, $new['rowVersion']);

            return $new;
        });
    }

    /** @return array<string, mixed> */
    public function priceView(object $p): array
    {
        return ['id' => Ids::fromBinary($p->id), 'priceListId' => Ids::fromBinary($p->price_list_id), 'productId' => Ids::fromBinary($p->product_id), 'facilityId' => Fmt::u($p->facility_unit_id),
            'amount' => Money::normalize($p->amount), 'validFrom' => Fmt::ts($p->valid_from), 'validTo' => Fmt::ts($p->valid_to), 'active' => (bool) $p->is_active, 'rowVersion' => (int) $p->row_version];
    }

    /**
     * Effective-dated price. The previous open-ended price of the same scope is end-dated at the new `validFrom`; overlapping with a
     * price that starts later (or a bounded price) is refused (409 price_overlap).
     *
     * @param  array<string, mixed>  $in  productId, priceListId?, facilityId?, amount, validFrom?, validTo?
     * @return array<string, mixed>
     */
    public function createPrice(array $in): array
    {
        return DB::transaction(function () use ($in): array {
            $product = $this->lockProduct($in['productId']);
            $listBin = isset($in['priceListId']) ? Ids::toBinary($in['priceListId']) : (DB::table('price_list')->where('organization_id', $this->orgBin())->where('is_default', 1)->where('is_active', 1)->value('id')
                ?? throw ApiProblem::unprocessable('validation_failed', 'There is no default price list. Create one first.', ['priceListId' => ['No default price list.']]));
            if (DB::table('price_list')->where('id', $listBin)->where('organization_id', $this->orgBin())->where('is_active', 1)->doesntExist()) {
                throw ApiProblem::unprocessable('validation_failed', 'Unknown or inactive price list.', ['priceListId' => ['Choose an active price list.']]);
            }
            $facBin = null;
            if (! empty($in['facilityId'])) {
                $facBin = Ids::toBinary($in['facilityId']);
                if (DB::table('facility_unit')->where('id', $facBin)->where('organization_id', $this->orgBin())->whereNull('deleted_at')->doesntExist()) {
                    throw ApiProblem::unprocessable('validation_failed', 'Unknown facility.', ['facilityId' => ['Unknown facility.']]);
                }
            }
            $from = isset($in['validFrom']) ? Fmt::clientTs($in['validFrom']) : Fmt::now();
            $to = isset($in['validTo']) ? Fmt::clientTs($in['validTo']) : null;
            if ($from === null || (isset($in['validTo']) && $to === null)) {
                throw ApiProblem::unprocessable('validation_failed', 'Invalid date.', ['validFrom' => ['Use ISO-8601 timestamps.']]);
            }
            if ($to !== null && $to <= $from) {
                throw ApiProblem::unprocessable('validation_failed', 'validTo must be after validFrom.', ['validTo' => ['Must be after validFrom.']]);
            }
            $scope = fn ($q) => $facBin === null ? $q->whereNull('facility_unit_id') : $q->where('facility_unit_id', $facBin);
            $rows = $scope(DB::table('price')->where('product_id', $product->id)->where('price_list_id', $listBin)->where('is_active', 1))->lockForUpdate()->get();
            foreach ($rows as $r) {
                $rTo = $r->valid_to;
                $overlaps = $r->valid_from < ($to ?? '9999-12-31 00:00:00.000000') && ($rTo === null || $rTo > $from);
                if (! $overlaps) {
                    continue;
                }
                if ($r->valid_from < $from) { // supersede: end-date the earlier price at the new start
                    DB::table('price')->where('id', $r->id)->update(['valid_to' => $from, 'row_version' => $r->row_version + 1]);
                    Outbox::record('ConfigurationUpdated', 'Price', Ids::fromBinary($r->id), ['domain' => 'price', 'changes' => ['validTo' => Fmt::ts($from)]], (int) $r->row_version + 1, facilityId: Fmt::u($facBin));

                    continue;
                }
                throw ApiProblem::conflict('price_overlap', 'That window overlaps an existing price for this product, list and facility. End-date or deactivate it first.', ['conflictingPriceId' => Ids::fromBinary($r->id)]);
            }
            $id = Ids::uuid7();
            DB::table('price')->insert(['id' => Ids::toBinary($id), 'price_list_id' => $listBin, 'product_id' => $product->id, 'facility_unit_id' => $facBin, 'amount' => Money::normalize($in['amount']),
                'valid_from' => $from, 'valid_to' => $to, 'is_active' => ($in['active'] ?? true) ? 1 : 0, 'row_version' => 1]);
            DB::table('product')->where('id', $product->id)->update(['updated_at' => Fmt::now()]);
            $v = $this->priceView(DB::table('price')->where('id', Ids::toBinary($id))->first());
            ConfigChange::record('config.catalog.price.create', 'Price', $id, null, $v, 'price', $v, 1, facilityId: $in['facilityId'] ?? null);

            return $v;
        });
    }

    /** @param array<string, mixed> $in amount?, validTo?, active? @return array<string, mixed> */
    public function updatePrice(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch): array {
            $r = Ids::isUuid($id) ? DB::table('price as p')->join('product as pr', 'pr.id', '=', 'p.product_id')->where('p.id', Ids::toBinary($id))->where('pr.organization_id', $this->orgBin())->lockForUpdate()->first(['p.*']) : null;
            if ($r === null) {
                throw ApiProblem::notFound('not_found', 'Price was not found.');
            }
            Concurrency::assertVersion((int) $r->row_version, $ifMatch, 'price');
            $old = $this->priceView($r);
            $now = Fmt::now();
            $set = [];
            if (array_key_exists('amount', $in) && Money::normalize($in['amount']) !== Money::normalize($r->amount)) {
                if ($r->valid_from <= $now) {
                    throw ApiProblem::conflict('price_immutable', 'This price is already in effect. End-date it (validTo) and create a new price instead.');
                }
                $set['amount'] = Money::normalize($in['amount']);
            }
            if (array_key_exists('validTo', $in)) {
                $to = $in['validTo'] === null ? null : Fmt::clientTs($in['validTo']);
                if ($in['validTo'] !== null && ($to === null || $to <= $r->valid_from)) {
                    throw ApiProblem::unprocessable('validation_failed', 'validTo must be after validFrom.', ['validTo' => ['Must be after validFrom.']]);
                }
                $set['valid_to'] = $to;
            }
            if (array_key_exists('active', $in)) {
                $set['is_active'] = $in['active'] ? 1 : 0;
            }
            if ($set === []) {
                return $old;
            }
            DB::table('price')->where('id', $r->id)->update($set + ['row_version' => $r->row_version + 1]);
            DB::table('product')->where('id', $r->product_id)->update(['updated_at' => Fmt::now()]);
            $new = $this->priceView(DB::table('price')->where('id', $r->id)->first());
            ConfigChange::record('config.catalog.price.update', 'Price', $id, $old, $new, 'price', array_intersect_key($new, array_flip(array_keys($set) === [] ? [] : ['amount', 'validTo', 'active'])), $new['rowVersion'], facilityId: $new['facilityId']);

            return $new;
        });
    }

    // ---- tax rates / prep routes -------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function taxView(object $r): array
    {
        return ['id' => Ids::fromBinary($r->id), 'code' => $r->code, 'name' => $r->name, 'ratePercent' => rtrim(rtrim((string) $r->rate_percent, '0'), '.') ?: '0', 'active' => (bool) $r->is_active, 'rowVersion' => (int) $r->row_version];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function createTaxRate(array $in): array
    {
        try {
            return DB::transaction(function () use ($in): array {
                $id = Ids::uuid7();
                DB::table('tax_rate')->insert(['id' => Ids::toBinary($id), 'organization_id' => $this->orgBin(), 'code' => strtoupper($in['code']), 'name' => $in['name'], 'rate_percent' => $in['ratePercent'], 'is_active' => ($in['active'] ?? true) ? 1 : 0]);
                $v = $this->taxView(DB::table('tax_rate')->where('id', Ids::toBinary($id))->first());
                ConfigChange::record('config.catalog.tax_rate.create', 'TaxRate', $id, null, $v, 'taxRate', $v + ['organizationId' => $this->org()], 1);

                return $v;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiProblem::conflict('tax_rate_code_taken', 'A tax rate with that code already exists.');
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function updateTaxRate(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch): array {
            $r = Ids::isUuid($id) ? DB::table('tax_rate')->where('id', Ids::toBinary($id))->where('organization_id', $this->orgBin())->lockForUpdate()->first() : null;
            $r ?? throw ApiProblem::notFound('not_found', 'Tax rate was not found.');
            Concurrency::assertVersion((int) $r->row_version, $ifMatch, 'tax rate');
            $old = $this->taxView($r);
            $set = [];
            foreach (['name' => 'name', 'ratePercent' => 'rate_percent'] as $k => $col) {
                if (isset($in[$k])) {
                    $set[$col] = $in[$k];
                }
            }
            if (array_key_exists('active', $in)) {
                $set['is_active'] = $in['active'] ? 1 : 0;
            }
            if ($set === []) {
                return $old;
            }
            $probe = $this->taxView((object) array_merge((array) $r, $set));
            if ($probe === $old) {
                return $old;
            }
            DB::table('tax_rate')->where('id', $r->id)->update($set + ['row_version' => $r->row_version + 1]);
            $new = $this->taxView(DB::table('tax_rate')->where('id', $r->id)->first());
            ConfigChange::record('config.catalog.tax_rate.update', 'TaxRate', $id, $old, $new, 'taxRate', $new, $new['rowVersion']);

            return $new;
        });
    }

    /** @return array<string, mixed> */
    public function routeView(object $r): array
    {
        return ['id' => Ids::fromBinary($r->id), 'code' => $r->code, 'name' => $r->name, 'kind' => $r->kind, 'rowVersion' => (int) $r->row_version];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function createPrepRoute(array $in): array
    {
        try {
            return DB::transaction(function () use ($in): array {
                $id = Ids::uuid7();
                DB::table('prep_route')->insert(['id' => Ids::toBinary($id), 'organization_id' => $this->orgBin(), 'code' => strtoupper($in['code']), 'name' => $in['name'], 'kind' => $in['kind']]);
                $v = $this->routeView(DB::table('prep_route')->where('id', Ids::toBinary($id))->first());
                ConfigChange::record('config.catalog.prep_route.create', 'PrepRoute', $id, null, $v, 'prepRoute', $v + ['organizationId' => $this->org()], 1);

                return $v;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiProblem::conflict('prep_route_code_taken', 'A prep route with that code already exists.');
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function updatePrepRoute(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch): array {
            $r = Ids::isUuid($id) ? DB::table('prep_route')->where('id', Ids::toBinary($id))->where('organization_id', $this->orgBin())->lockForUpdate()->first() : null;
            $r ?? throw ApiProblem::notFound('not_found', 'Prep route was not found.');
            Concurrency::assertVersion((int) $r->row_version, $ifMatch, 'prep route');
            $old = $this->routeView($r);
            $set = array_filter(['name' => $in['name'] ?? null, 'kind' => $in['kind'] ?? null], fn ($v) => $v !== null);
            if (isset($set['kind']) && $set['kind'] !== $r->kind && DB::table('product')->where('prep_route_id', $r->id)->exists()) {
                throw ApiProblem::conflict('prep_route_in_use', 'Products use this route; changing its kind would re-route them. Create a new route instead.');
            }
            if ($set === [] || array_diff_assoc($set, ['name' => $r->name, 'kind' => $r->kind]) === []) {
                return $old;
            }
            DB::table('prep_route')->where('id', $r->id)->update($set + ['row_version' => $r->row_version + 1]);
            $new = $this->routeView(DB::table('prep_route')->where('id', $r->id)->first());
            ConfigChange::record('config.catalog.prep_route.update', 'PrepRoute', $id, $old, $new, 'prepRoute', $new, $new['rowVersion']);

            return $new;
        });
    }

    /** @return list<array<string, mixed>> */
    public function prepRouteStations(?string $facilityId): array
    {
        $q = DB::table('prep_route_station as s')->join('facility_unit as f', 'f.id', '=', 's.facility_unit_id')->where('f.organization_id', $this->orgBin());
        if ($facilityId !== null) {
            $q->where('s.facility_unit_id', Ids::toBinary($facilityId));
        }

        return $q->orderBy('f.code')->get(['s.*'])->map(fn ($r) => ['facilityId' => Ids::fromBinary($r->facility_unit_id), 'prepRouteId' => Ids::fromBinary($r->prep_route_id), 'kdsStationId' => Ids::fromBinary($r->kds_station_id)])->all();
    }

    /** @param array<string, mixed> $in facilityId, prepRouteId, kdsStationId|null @return array<string, mixed> */
    public function setPrepRouteStation(array $in): array
    {
        return DB::transaction(function () use ($in): array {
            $f = DB::table('facility_unit')->where('id', Ids::toBinary($in['facilityId']))->where('organization_id', $this->orgBin())->whereNull('deleted_at')->first() ?? throw ApiProblem::unprocessable('validation_failed', 'Unknown facility.', ['facilityId' => ['Unknown facility.']]);
            $route = DB::table('prep_route')->where('id', Ids::toBinary($in['prepRouteId']))->where('organization_id', $this->orgBin())->first() ?? throw ApiProblem::unprocessable('validation_failed', 'Unknown prep route.', ['prepRouteId' => ['Unknown prep route.']]);
            $cur = DB::table('prep_route_station')->where('facility_unit_id', $f->id)->where('prep_route_id', $route->id)->lockForUpdate()->first();
            $old = $cur === null ? null : Ids::fromBinary($cur->kds_station_id);
            if ($in['kdsStationId'] === null) {
                DB::table('prep_route_station')->where('facility_unit_id', $f->id)->where('prep_route_id', $route->id)->delete();
                $new = null;
            } else {
                $st = DB::table('kds_station')->where('id', Ids::toBinary($in['kdsStationId']))->where('organization_id', $this->orgBin())->where('is_active', 1)->first()
                    ?? throw ApiProblem::unprocessable('validation_failed', 'Unknown or inactive KDS station.', ['kdsStationId' => ['Choose an active KDS station.']]);
                $stationKind = $st->prep_route_id === null ? $st->kind : DB::table('prep_route')->where('id', $st->prep_route_id)->value('kind');
                if ($route->kind !== 'NONE' && $stationKind !== $route->kind && $st->kind !== 'DISPENSE') {
                    throw ApiProblem::unprocessable('validation_failed', "That station prepares {$stationKind} orders, not {$route->kind}.", ['kdsStationId' => ['The station serves another kind of route.']]);
                }
                DB::table('prep_route_station')->updateOrInsert(['facility_unit_id' => $f->id, 'prep_route_id' => $route->id], ['kds_station_id' => $st->id]);
                $new = Ids::fromBinary($st->id);
            }
            if ($old !== $new) {
                $v = ConfigVersion::next(ConfigIds::pair($in['facilityId'], $in['prepRouteId']));
                ConfigChange::record('config.catalog.prep_route_station.set', 'PrepRoute', $in['prepRouteId'], ['facilityId' => $in['facilityId'], 'kdsStationId' => $old], ['facilityId' => $in['facilityId'], 'kdsStationId' => $new],
                    'prepRouteStation', ['facilityId' => $in['facilityId'], 'prepRouteId' => $in['prepRouteId'], 'kdsStationId' => $new], $v, facilityId: $in['facilityId'], outboxEntityType: 'PrepRouteStation', outboxEntityId: ConfigIds::pair($in['facilityId'], $in['prepRouteId']));
            }

            return ['facilityId' => $in['facilityId'], 'prepRouteId' => $in['prepRouteId'], 'kdsStationId' => $new];
        });
    }

    /** @return array<string, mixed> */
    public function setCategoryPrepRoute(string $categoryId, ?string $routeId, bool $apply): array
    {
        return DB::transaction(function () use ($categoryId, $routeId, $apply): array {
            $cat = Ids::isUuid($categoryId) ? DB::table('product_category')->where('id', Ids::toBinary($categoryId))->where('organization_id', $this->orgBin())->first() : null;
            $cat ?? throw ApiProblem::notFound('not_found', 'Category was not found.');
            if ($routeId !== null && DB::table('prep_route')->where('id', Ids::toBinary($routeId))->where('organization_id', $this->orgBin())->doesntExist()) {
                throw ApiProblem::unprocessable('validation_failed', 'Unknown prep route.', ['prepRouteId' => ['Unknown prep route.']]);
            }
            $updated = 0;
            if ($apply) {
                $rows = DB::table('product')->where('category_id', $cat->id)->whereNull('deleted_at')->lockForUpdate()->get(['id', 'row_version', 'prep_route_id']);
                foreach ($rows as $p) {
                    if (Fmt::u($p->prep_route_id) === $routeId) {
                        continue;
                    }
                    DB::table('product')->where('id', $p->id)->update(['prep_route_id' => Fmt::b($routeId), 'row_version' => $p->row_version + 1]);
                    Outbox::record('ConfigurationUpdated', 'Product', Ids::fromBinary($p->id), ['domain' => 'product', 'changes' => ['prepRouteId' => $routeId]], (int) $p->row_version + 1);
                    $updated++;
                }
            }
            Audit::record('config.catalog.category.prep_route', 'ProductCategory', $categoryId, null, ['prepRouteId' => $routeId, 'appliedToProducts' => $apply, 'productsUpdated' => $updated]);

            return ['categoryId' => $categoryId, 'prepRouteId' => $routeId, 'productsUpdated' => $updated];
        });
    }

    // ---- product <-> stock links -------------------------------------------------------------------------------------------

    /** @return array{links: list<array<string, mixed>>} */
    public function stockLinks(string $productId): array
    {
        $rows = DB::table('product_stock_link')->where('product_id', Ids::toBinary($productId))->orderBy('created_at')->get();

        return ['links' => $rows->map(fn ($r) => ['stockItemId' => Ids::fromBinary($r->stock_item_id), 'quantityPerUnit' => Money::normalize($r->quantity_per_unit)])->all()];
    }

    /** @param list<array{stockItemId: string, quantityPerUnit: string}> $links @return array{links: list<array<string, mixed>>} */
    public function setStockLinks(string $productId, array $links): array
    {
        return DB::transaction(function () use ($productId, $links): array {
            $p = $this->lockProduct($productId);
            $seen = [];
            foreach ($links as $i => $l) {
                $sid = Ids::normalize($l['stockItemId']);
                if (isset($seen[$sid])) {
                    throw ApiProblem::unprocessable('validation_failed', 'A stock item can only be linked once.', ["links.{$i}.stockItemId" => ['Duplicate stock item.']]);
                }
                $seen[$sid] = true;
                if (DB::table('inventory_item')->where('id', Ids::toBinary($sid))->where('organization_id', $this->orgBin())->doesntExist()) {
                    throw ApiProblem::unprocessable('validation_failed', 'Unknown stock item.', ["links.{$i}.stockItemId" => ['Unknown stock item.']]);
                }
                if (! Money::isValid($l['quantityPerUnit']) || bccomp(Money::normalize($l['quantityPerUnit']), '0', 4) <= 0) {
                    throw ApiProblem::unprocessable('validation_failed', 'quantityPerUnit must be greater than zero.', ["links.{$i}.quantityPerUnit" => ['Must be greater than zero.']]);
                }
            }
            $old = $this->stockLinks($productId)['links'];
            DB::table('product_stock_link')->where('product_id', $p->id)->delete();
            foreach ($links as $l) {
                DB::table('product_stock_link')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'product_id' => $p->id, 'stock_item_id' => Ids::toBinary($l['stockItemId']), 'quantity_per_unit' => Money::normalize($l['quantityPerUnit'])]);
            }
            $new = $this->stockLinks($productId)['links'];
            if ($old !== $new) {
                DB::table('product')->where('id', $p->id)->update(['row_version' => $p->row_version + 1, 'updated_at' => Fmt::now()]);
                ConfigChange::record('config.catalog.stock_links.set', 'Product', $productId, ['links' => $old], ['links' => $new], 'productStockLinks', ['links' => $new], (int) $p->row_version + 1);
            }

            return ['links' => $new];
        });
    }

    private function lockProduct(string $id): object
    {
        return Ids::isUuid($id) ? (DB::table('product')->where('id', Ids::toBinary($id))->where('organization_id', $this->orgBin())->whereNull('deleted_at')->lockForUpdate()->first()
            ?? throw ApiProblem::notFound('not_found', 'Product was not found.')) : throw ApiProblem::notFound('not_found', 'Product was not found.');
    }
}
