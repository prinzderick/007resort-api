<?php

namespace App\Domain\Catalog\Services;

use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/** Admin catalog writes: permissioned by the controller, always audited in the same transaction. */
final class CatalogAdmin
{
    /** @param array<string, mixed> $in */
    public function createCategory(array $in): array
    {
        return DB::transaction(function () use ($in) {
            $org = Tenant::organizationId();
            if (! empty($in['parentId'])) {
                $this->mustExist('product_category', $in['parentId'], $org);
            }
            $id = Ids::uuid7();
            DB::table('product_category')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($org), 'parent_id' => Fmt::b($in['parentId'] ?? null),
                'name' => $in['name'], 'sort_order' => $in['sortOrder'] ?? 0,
            ]);
            Audit::record('catalog.category.create', 'ProductCategory', $id, new: ['name' => $in['name'], 'parentId' => $in['parentId'] ?? null]);

            return $this->category($id);
        });
    }

    /** @param array<string, mixed> $in */
    public function updateCategory(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch) {
            $row = DB::table('product_category')->where('id', Ids::toBinary($id))->where('organization_id', Ids::toBinary(Tenant::organizationId()))->lockForUpdate()->first();
            if (! $row) {
                throw ApiProblem::notFound();
            }
            Concurrency::assertVersion($row->row_version, $ifMatch, 'category');
            $upd = [];
            foreach (['name' => 'name', 'sortOrder' => 'sort_order'] as $k => $col) {
                if (array_key_exists($k, $in)) {
                    $upd[$col] = $in[$k];
                }
            }
            if (array_key_exists('active', $in)) {
                $upd['is_active'] = $in['active'] ? 1 : 0;
            }
            $upd['row_version'] = $row->row_version + 1;
            DB::table('product_category')->where('id', $row->id)->update($upd);
            Audit::record('catalog.category.update', 'ProductCategory', $id, old: ['name' => $row->name, 'sortOrder' => $row->sort_order, 'active' => (bool) $row->is_active], new: $in);

            return $this->category($id);
        });
    }

    /** @param array<string, mixed> $in */
    public function createProduct(array $in): array
    {
        return DB::transaction(function () use ($in) {
            $org = Tenant::organizationId();
            $this->mustExist('product_category', $in['categoryId'], $org);
            if (! empty($in['prepRouteId'])) {
                $this->mustExist('prep_route', $in['prepRouteId'], $org);
            }
            if (! empty($in['taxRateId'])) {
                $this->mustExist('tax_rate', $in['taxRateId'], $org);
            }
            if (DB::table('product')->where('organization_id', Ids::toBinary($org))->where('sku', $in['sku'])->exists()) {
                throw ApiProblem::conflict('sku_taken', 'A product with that SKU already exists.');
            }
            $id = Ids::uuid7();
            DB::table('product')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($org), 'category_id' => Ids::toBinary($in['categoryId']),
                'sku' => $in['sku'], 'name' => $in['name'], 'kind' => $in['kind'] ?? 'GOOD',
                'tax_rate_id' => Fmt::b($in['taxRateId'] ?? null), 'tax_exempt' => ! empty($in['taxExempt']) ? 1 : 0,
                'prep_route_id' => Fmt::b($in['prepRouteId'] ?? null), 'track_stock' => ! empty($in['trackStock']) ? 1 : 0,
                'image_url' => $in['imageUrl'] ?? null,
            ]);
            foreach ($in['facilityIds'] ?? [] as $fid) {
                DB::table('product_facility')->insertOrIgnore(['product_id' => Ids::toBinary($id), 'facility_unit_id' => Ids::toBinary($fid)]);
            }
            if (isset($in['price'])) {
                $this->writePrice($id, $in['price'], null);
            }
            Audit::record('catalog.product.create', 'Product', $id, new: ['sku' => $in['sku'], 'name' => $in['name'], 'kind' => $in['kind'] ?? 'GOOD', 'price' => $in['price'] ?? null]);

            return $this->product($id);
        });
    }

    /** @param array<string, mixed> $in */
    public function updateProduct(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch) {
            $org = Tenant::organizationId();
            $row = DB::table('product')->where('id', Ids::toBinary($id))->where('organization_id', Ids::toBinary($org))->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $row) {
                throw ApiProblem::notFound();
            }
            Concurrency::assertVersion($row->row_version, $ifMatch, 'product');
            $map = ['name' => 'name', 'kind' => 'kind', 'imageUrl' => 'image_url'];
            $upd = [];
            foreach ($map as $k => $col) {
                if (array_key_exists($k, $in)) {
                    $upd[$col] = $in[$k];
                }
            }
            foreach (['categoryId' => 'category_id', 'prepRouteId' => 'prep_route_id', 'taxRateId' => 'tax_rate_id'] as $k => $col) {
                if (array_key_exists($k, $in)) {
                    if ($in[$k] !== null) {
                        $this->mustExist(['categoryId' => 'product_category', 'prepRouteId' => 'prep_route', 'taxRateId' => 'tax_rate'][$k], $in[$k], $org);
                    }
                    $upd[$col] = Fmt::b($in[$k]);
                }
            }
            foreach (['taxExempt' => 'tax_exempt', 'trackStock' => 'track_stock', 'active' => 'is_active'] as $k => $col) {
                if (array_key_exists($k, $in)) {
                    $upd[$col] = $in[$k] ? 1 : 0;
                }
            }
            $upd['row_version'] = $row->row_version + 1;
            DB::table('product')->where('id', $row->id)->update($upd);
            Audit::record('catalog.product.update', 'Product', $id,
                old: ['name' => $row->name, 'kind' => $row->kind, 'active' => (bool) $row->is_active], new: $in);

            return $this->product($id);
        });
    }

    /** Set (append) a price: supersedes the currently active price for the same (product, facility). */
    public function setPrice(string $productId, string $amount, ?string $facilityId): array
    {
        return DB::transaction(function () use ($productId, $amount, $facilityId) {
            $row = DB::table('product')->where('id', Ids::toBinary($productId))->where('organization_id', Ids::toBinary(Tenant::organizationId()))->lockForUpdate()->first();
            if (! $row) {
                throw ApiProblem::notFound();
            }
            if (! Money::isValid($amount) || str_starts_with(trim((string) $amount), '-')) {
                throw ApiProblem::unprocessable('validation_failed', 'amount must be a non-negative decimal string.');
            }
            $old = $this->writePrice($productId, $amount, $facilityId);
            DB::table('product')->where('id', $row->id)->update(['row_version' => $row->row_version + 1]);
            Audit::record('catalog.price.set', 'Product', $productId, old: ['amount' => $old, 'facilityId' => $facilityId], new: ['amount' => Money::normalize($amount), 'facilityId' => $facilityId], facilityUnitId: $facilityId);

            return $this->product($productId);
        });
    }

    private function writePrice(string $productId, string $amount, ?string $facilityId): ?string
    {
        $list = DB::table('price_list')->where('organization_id', Ids::toBinary(Tenant::organizationId()))->where('is_default', 1)->where('is_active', 1)->first();
        if (! $list) {
            $lid = Ids::uuid7();
            DB::table('price_list')->insert(['id' => Ids::toBinary($lid), 'organization_id' => Ids::toBinary(Tenant::organizationId()), 'name' => 'Standard', 'is_default' => 1]);
            $listBin = Ids::toBinary($lid);
        } else {
            $listBin = $list->id;
        }
        $q = DB::table('price')->where('product_id', Ids::toBinary($productId))->where('price_list_id', $listBin)->where('is_active', 1);
        $facilityId === null ? $q->whereNull('facility_unit_id') : $q->where('facility_unit_id', Ids::toBinary($facilityId));
        $prev = (clone $q)->value('amount');
        $now = Fmt::now();
        $q->update(['is_active' => 0, 'valid_to' => $now]);
        DB::table('price')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'price_list_id' => $listBin, 'product_id' => Ids::toBinary($productId),
            'facility_unit_id' => Fmt::b($facilityId), 'amount' => Money::normalize($amount), 'valid_from' => $now,
        ]);

        return $prev === null ? null : Money::normalize($prev);
    }

    private function mustExist(string $table, string $id, string $org): void
    {
        if (! DB::table($table)->where('id', Ids::toBinary($id))->where('organization_id', Ids::toBinary($org))->exists()) {
            throw ApiProblem::unprocessable('validation_failed', "Referenced {$table} does not exist.");
        }
    }

    /** @return array<string, mixed> */
    public function category(string $id): array
    {
        $r = DB::table('product_category')->where('id', Ids::toBinary($id))->first();

        return ['id' => $id, 'parentId' => Fmt::u($r->parent_id), 'name' => $r->name, 'sortOrder' => (int) $r->sort_order, 'active' => (bool) $r->is_active, 'rowVersion' => (int) $r->row_version];
    }

    /** Admin view of a product (not facility-resolved). */
    public function product(string $id): array
    {
        $r = DB::table('product as p')->leftJoin('prep_route as pr', 'pr.id', '=', 'p.prep_route_id')->where('p.id', Ids::toBinary($id))->first(['p.*', 'pr.kind as route_kind']);
        $prices = DB::table('price')->where('product_id', $r->id)->where('is_active', 1)->get(['facility_unit_id', 'amount']);

        return [
            'id' => $id, 'sku' => $r->sku, 'name' => $r->name, 'categoryId' => Fmt::u($r->category_id),
            'kind' => CatalogService::contractKind($r->kind, $r->route_kind ?? 'NONE'), 'commercialKind' => $r->kind,
            'prepRouteId' => Fmt::u($r->prep_route_id), 'taxRateId' => Fmt::u($r->tax_rate_id), 'taxExempt' => (bool) $r->tax_exempt,
            'trackStock' => (bool) $r->track_stock, 'active' => (bool) $r->is_active, 'imageUrl' => $r->image_url,
            'prices' => $prices->map(fn ($p) => ['facilityId' => Fmt::u($p->facility_unit_id), 'amount' => Money::normalize($p->amount)])->all(),
            'rowVersion' => (int) $r->row_version,
        ];
    }
}
