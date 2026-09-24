<?php

namespace App\Domain\Catalog\Services;

use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
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
            Outbox::record('ConfigurationUpdated', 'ProductCategory', $id, ['domain' => 'productCategory', 'changes' => $this->category($id) + ['organizationId' => $org]], 1);

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
            Outbox::record('ConfigurationUpdated', 'ProductCategory', $id, ['domain' => 'productCategory', 'changes' => $this->category($id)], (int) $upd['row_version']);

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
            $this->assertBarcodeFree($org, $in['barcode'] ?? null, null);
            $id = Ids::uuid7();
            DB::table('product')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($org), 'category_id' => Ids::toBinary($in['categoryId']),
                'sku' => $in['sku'], 'name' => $in['name'], 'kind' => $in['kind'] ?? 'GOOD',
                'tax_rate_id' => Fmt::b($in['taxRateId'] ?? null), 'tax_exempt' => ! empty($in['taxExempt']) ? 1 : 0,
                'prep_route_id' => Fmt::b($in['prepRouteId'] ?? null), 'track_stock' => ! empty($in['trackStock']) ? 1 : 0,
                'image_url' => $in['imageUrl'] ?? null, 'description' => $in['description'] ?? null, 'barcode' => $in['barcode'] ?? null,
                'modifiers' => isset($in['modifiers']) ? json_encode($in['modifiers']) : null,
            ]);
            foreach ($in['facilityIds'] ?? [] as $fid) {
                DB::table('product_facility')->insertOrIgnore(['product_id' => Ids::toBinary($id), 'facility_unit_id' => Ids::toBinary($fid)]);
            }
            if (isset($in['price'])) {
                $this->writePrice($id, $in['price'], null);
            }
            Audit::record('catalog.product.create', 'Product', $id, new: ['sku' => $in['sku'], 'name' => $in['name'], 'kind' => $in['kind'] ?? 'GOOD', 'price' => $in['price'] ?? null]);
            Outbox::record('ConfigurationUpdated', 'Product', $id, ['domain' => 'product', 'changes' => $this->syncSnapshot($id)], 1);

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
            $map = ['name' => 'name', 'kind' => 'kind', 'imageUrl' => 'image_url', 'description' => 'description', 'barcode' => 'barcode'];
            if (array_key_exists('barcode', $in)) {
                $this->assertBarcodeFree($org, $in['barcode'], $row->id);
            }
            $upd = [];
            if (array_key_exists('modifiers', $in)) {
                $upd['modifiers'] = $in['modifiers'] === null ? null : json_encode($in['modifiers']);
            }
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
            Outbox::record('ConfigurationUpdated', 'Product', $id, ['domain' => 'product', 'changes' => $this->syncSnapshot($id)], (int) $upd['row_version']);

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
        $supersededRows = (clone $q)->get(['id', 'row_version']);
        $q->update(['is_active' => 0, 'valid_to' => $now, 'row_version' => DB::raw('row_version + 1')]);
        foreach ($supersededRows as $sr) {
            Outbox::record('ConfigurationUpdated', 'Price', Ids::fromBinary($sr->id), ['domain' => 'price', 'changes' => ['active' => false, 'validTo' => Fmt::ts($now)]], (int) $sr->row_version + 1, facilityId: $facilityId);
        }
        $newId = Ids::uuid7();
        DB::table('price')->insert([
            'id' => Ids::toBinary($newId), 'price_list_id' => $listBin, 'product_id' => Ids::toBinary($productId),
            'facility_unit_id' => Fmt::b($facilityId), 'amount' => Money::normalize($amount), 'valid_from' => $now,
        ]);
        Outbox::record('ConfigurationUpdated', 'Price', $newId, ['domain' => 'price', 'changes' => ['price' => [
            'id' => $newId, 'priceListId' => Ids::fromBinary($listBin), 'productId' => $productId, 'facilityId' => $facilityId, 'amount' => Money::normalize($amount),
            'validFrom' => Fmt::ts($now), 'validTo' => null, 'active' => true, 'rowVersion' => 1]]], 1, facilityId: $facilityId);

        return $prev === null ? null : Money::normalize($prev);
    }

    private function assertBarcodeFree(string $org, ?string $barcode, ?string $exceptProductBin): void
    {
        if ($barcode === null || $barcode === '') {
            return;
        }
        $q = DB::table('product')->where('organization_id', Ids::toBinary($org))->where('barcode', $barcode);
        if ($exceptProductBin !== null) {
            $q->where('id', '!=', $exceptProductBin);
        }
        if ($q->exists()) {
            throw ApiProblem::conflict('barcode_taken', 'Another product already uses that barcode.');
        }
    }

    /** Sync snapshot of the editable product columns (camelCase, syncable by the Config product target). @return array<string, mixed> */
    public function syncSnapshot(string $id): array
    {
        $r = DB::table('product')->where('id', Ids::toBinary($id))->first();

        return [
            'organizationId' => Ids::fromBinary($r->organization_id), 'categoryId' => Ids::fromBinary($r->category_id), 'sku' => $r->sku, 'name' => $r->name, 'kind' => $r->kind,
            'taxRateId' => Fmt::u($r->tax_rate_id), 'taxExempt' => (bool) $r->tax_exempt, 'prepRouteId' => Fmt::u($r->prep_route_id), 'trackStock' => (bool) $r->track_stock,
            'imageUrl' => $r->image_url, 'description' => $r->description, 'barcode' => $r->barcode, 'modifiers' => $r->modifiers === null ? null : json_decode($r->modifiers, true),
            'isActive' => (bool) $r->is_active,
        ];
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
            'description' => $r->description, 'barcode' => $r->barcode, 'modifiers' => $r->modifiers === null ? null : json_decode($r->modifiers, true),
            'prices' => $prices->map(fn ($p) => ['facilityId' => Fmt::u($p->facility_unit_id), 'amount' => Money::normalize($p->amount)])->all(),
            'rowVersion' => (int) $r->row_version,
        ];
    }
}
