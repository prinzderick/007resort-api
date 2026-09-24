<?php

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Inventory\Services\InventoryAccess;
use App\Domain\Inventory\Services\RentalService;
use App\Domain\Inventory\Support\Dto;
use App\Domain\Inventory\Support\Qty;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Http\Etag;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Master data + read models: items, locations, suppliers, balances, the ledger, rental assets. */
class InventoryController
{
    public const QTY = ['regex:/^\d{1,15}(\.\d{1,4})?$/'];

    private const NO_ROWS = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    public function __construct(private readonly InventoryAccess $access) {}

    // ---- items ----------------------------------------------------------------------------------------------------

    public function items(Request $request): JsonResponse
    {
        $q = DB::table('inventory_item')->where('organization_id', Ids::toBinary($this->org()));
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like));
        }
        if ($request->query('active') !== null) {
            $q->where('is_active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }
        if ($cat = $request->query('category')) {
            $q->where('category', (string) $cat);
        }

        return $this->page($q, $request, 'id', 'asc', Dto::item(...));
    }

    public function createItem(Request $request): JsonResponse
    {
        $d = $request->validate([
            'sku' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:200'], 'unit' => ['sometimes', 'string', 'max:16'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'], 'reorderLevel' => ['sometimes', 'string', ...self::QTY], 'active' => ['sometimes', 'boolean'],
        ]);
        $id = Ids::uuid7();
        try {
            DB::transaction(function () use ($d, $id) {
                DB::table('inventory_item')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($this->org()), 'sku' => $d['sku'], 'name' => $d['name'],
                    'unit' => $d['unit'] ?? 'EACH', 'category' => $d['category'] ?? null, 'reorder_level' => $d['reorderLevel'] ?? '0', 'is_active' => ($d['active'] ?? true) ? 1 : 0,
                ]);
                Audit::record('inventory.item.create', 'InventoryItem', $id, null, $d);
            });
        } catch (QueryException $e) {
            throw $this->dup($e, 'sku', 'An item with that SKU already exists.');
        }

        return Etag::json(Dto::item($this->row('inventory_item', $id)), 1, 201);
    }

    public function updateItem(Request $request, string $item): JsonResponse
    {
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'], 'unit' => ['sometimes', 'string', 'max:16'], 'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reorderLevel' => ['sometimes', 'string', ...self::QTY], 'active' => ['sometimes', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $item, $d) {
            $row = DB::table('inventory_item')->where('id', Ids::toBinary($this->uuid($item)))->where('organization_id', Ids::toBinary($this->org()))->lockForUpdate()->first();
            if (! $row) {
                throw ApiProblem::notFound('not_found', 'That inventory item does not exist.');
            }
            Etag::assertMatches($request, (int) $row->row_version);
            $set = [];
            foreach (['name' => 'name', 'unit' => 'unit', 'category' => 'category', 'reorderLevel' => 'reorder_level'] as $in => $col) {
                if (array_key_exists($in, $d)) {
                    $set[$col] = $d[$in];
                }
            }
            if (array_key_exists('active', $d)) {
                $set['is_active'] = $d['active'] ? 1 : 0;
            }
            if ($set !== []) {
                $set['row_version'] = $row->row_version + 1;
                DB::table('inventory_item')->where('id', $row->id)->update($set);
                Audit::record('inventory.item.update', 'InventoryItem', Ids::fromBinary($row->id), Dto::item($row), $d);
            }
            $fresh = DB::table('inventory_item')->where('id', $row->id)->first();

            return Etag::json(Dto::item($fresh), (int) $fresh->row_version);
        });
    }

    // ---- locations ------------------------------------------------------------------------------------------------

    public function locations(Request $request): JsonResponse
    {
        $visible = $this->access->visibleLocationIds('inventory.view');
        $q = DB::table('stock_location')->where('organization_id', Ids::toBinary($this->org()));
        if ($visible !== null) {
            $q->whereIn('id', array_map(Ids::toBinary(...), $visible) ?: [self::NO_ROWS]);
        }
        if ($f = $request->query('facilityId')) {
            $q->where('facility_unit_id', Ids::toBinary($this->uuid((string) $f)));
        }

        return $this->page($q, $request, 'id', 'asc', Dto::location(...));
    }

    public function createLocation(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'kind' => ['required', Rule::in(['MAIN_STORE', 'FACILITY_STORE', 'BAR', 'KITCHEN', 'WASTE'])],
            'facilityId' => ['sometimes', 'nullable', 'uuid'], 'allowNegative' => ['sometimes', 'boolean'], 'isSaleDefault' => ['sometimes', 'boolean'],
        ]);
        $facilityId = $d['facilityId'] ?? null;
        if ($d['kind'] === 'MAIN_STORE' && $facilityId !== null) {
            throw ApiProblem::unprocessable('validation_failed', 'The main store is not tied to a facility.');
        }
        if (! in_array($d['kind'], ['MAIN_STORE', 'WASTE'], true) && $facilityId === null) {
            throw ApiProblem::unprocessable('validation_failed', 'facilityId is required for this kind of location.');
        }
        $site = Tenant::siteId();
        if ($facilityId !== null && ! DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->where('site_id', Ids::toBinary($site))->exists()) {
            throw ApiProblem::notFound('not_found', 'That facility does not exist.');
        }
        $id = Ids::uuid7();
        try {
            DB::transaction(function () use ($d, $id, $facilityId, $site) {
                DB::table('stock_location')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($this->org()), 'site_id' => Ids::toBinary($site),
                    'facility_unit_id' => $facilityId ? Ids::toBinary($facilityId) : null, 'name' => $d['name'], 'kind' => $d['kind'],
                    'allow_negative' => ($d['allowNegative'] ?? false) ? 1 : 0, 'is_sale_default' => ($d['isSaleDefault'] ?? false) ? 1 : 0,
                ]);
                Audit::record('inventory.location.create', 'StockLocation', $id, null, $d, facilityUnitId: $facilityId);
            });
        } catch (QueryException $e) {
            throw $this->dup($e, 'name', 'A stock location with that name already exists.');
        }

        return Etag::json(Dto::location($this->row('stock_location', $id)), 1, 201);
    }

    /** allowNegative is sensitive (it disables the stock guard for the location): audited with old/new. */
    public function updateLocation(Request $request, string $location): JsonResponse
    {
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'], 'allowNegative' => ['sometimes', 'boolean'],
            'isSaleDefault' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $location, $d) {
            $row = DB::table('stock_location')->where('id', Ids::toBinary($this->uuid($location)))->where('organization_id', Ids::toBinary($this->org()))->lockForUpdate()->first();
            if (! $row) {
                throw ApiProblem::notFound('not_found', 'That stock location does not exist.');
            }
            Etag::assertMatches($request, (int) $row->row_version);
            $set = [];
            if (array_key_exists('name', $d)) {
                $set['name'] = $d['name'];
            }
            foreach (['allowNegative' => 'allow_negative', 'isSaleDefault' => 'is_sale_default', 'active' => 'is_active'] as $in => $col) {
                if (array_key_exists($in, $d)) {
                    $set[$col] = $d[$in] ? 1 : 0;
                }
            }
            if ($set !== []) {
                $set['row_version'] = $row->row_version + 1;
                try {
                    DB::table('stock_location')->where('id', $row->id)->update($set);
                } catch (QueryException $e) {
                    throw $this->dup($e, 'name', 'A stock location with that name already exists.');
                }
                Audit::record('inventory.location.update', 'StockLocation', Ids::fromBinary($row->id), Dto::location($row), $d,
                    facilityUnitId: $row->facility_unit_id ? Ids::fromBinary($row->facility_unit_id) : null);
            }
            $fresh = DB::table('stock_location')->where('id', $row->id)->first();

            return Etag::json(Dto::location($fresh), (int) $fresh->row_version);
        });
    }

    // ---- suppliers ------------------------------------------------------------------------------------------------

    public function suppliers(Request $request): JsonResponse
    {
        $q = DB::table('supplier')->where('organization_id', Ids::toBinary($this->org()));
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%');
        }

        return $this->page($q, $request, 'id', 'asc', Dto::supplier(...));
    }

    public function createSupplier(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:200'], 'contactName' => ['sometimes', 'nullable', 'string', 'max:200'], 'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'], 'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $id = Ids::uuid7();
        try {
            DB::transaction(function () use ($d, $id) {
                DB::table('supplier')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($this->org()), 'name' => $d['name'], 'contact_name' => $d['contactName'] ?? null,
                    'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null, 'address' => $d['address'] ?? null,
                ]);
                Audit::record('supplier.create', 'Supplier', $id, null, $d);
            });
        } catch (QueryException $e) {
            throw $this->dup($e, 'name', 'A supplier with that name already exists.');
        }

        return response()->json(Dto::supplier($this->row('supplier', $id)), 201);
    }

    // ---- read models ----------------------------------------------------------------------------------------------

    /** GET /inventory/balances?locationId&itemId&belowReorder=1 — keyset paginated on (item_id, location_id). */
    public function balances(Request $request): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $q = DB::table('stock_balance as b')->join('inventory_item as i', 'i.id', '=', 'b.item_id')
            ->where('i.organization_id', Ids::toBinary($this->org()))
            ->select(['b.item_id', 'b.location_id', 'b.qty_on_hand', 'b.updated_at', 'i.name as item_name', 'i.unit', 'i.reorder_level']);

        $this->restrictToVisible($q, $request, 'b.location_id');
        if ($i = $request->query('itemId')) {
            $q->where('b.item_id', Ids::toBinary($this->uuid((string) $i)));
        }
        if (filter_var($request->query('belowReorder'), FILTER_VALIDATE_BOOLEAN)) {
            $q->whereColumn('b.qty_on_hand', '<=', 'i.reorder_level');
        }
        if ($cursor = $request->query('cursor')) {
            $parts = explode(':', (string) base64_decode(strtr((string) $cursor, '-_', '+/'), true));
            if (count($parts) !== 2 || ! Ids::isUuid($parts[0]) || ! Ids::isUuid($parts[1])) {
                throw ApiProblem::badRequest('invalid_cursor', 'The pagination cursor is invalid.');
            }
            $ib = Ids::toBinary($parts[0]);
            $lb = Ids::toBinary($parts[1]);
            $q->where(fn ($w) => $w->where('b.item_id', '>', $ib)->orWhere(fn ($x) => $x->where('b.item_id', $ib)->where('b.location_id', '>', $lb)));
        }
        $rows = $q->orderBy('b.item_id')->orderBy('b.location_id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $next = null;
        if ($more && ($last = $rows->last())) {
            $next = rtrim(strtr(base64_encode(Ids::fromBinary($last->item_id).':'.Ids::fromBinary($last->location_id)), '+/', '-_'), '=');
        }

        return response()->json([
            'items' => $rows->map(fn ($r) => [
                'itemId' => Ids::fromBinary($r->item_id), 'itemName' => $r->item_name, 'locationId' => Ids::fromBinary($r->location_id),
                'quantity' => Qty::normalize($r->qty_on_hand), 'unit' => $r->unit, 'reorderLevel' => Qty::normalize($r->reorder_level),
                'belowReorder' => Qty::cmp(Qty::normalize($r->qty_on_hand), Qty::normalize($r->reorder_level)) <= 0, 'updatedAt' => Dto::time($r->updated_at),
            ])->all(),
            'nextCursor' => $next,
        ]);
    }

    /** GET /inventory/movements — the immutable ledger, newest first. */
    public function movements(Request $request): JsonResponse
    {
        $q = DB::table('stock_movement')->where('organization_id', Ids::toBinary($this->org()));
        $this->restrictToVisible($q, $request, 'location_id');
        if ($i = $request->query('itemId')) {
            $q->where('item_id', Ids::toBinary($this->uuid((string) $i)));
        }
        if ($r = $request->query('reason')) {
            $q->where('reason', (string) $r);
        }
        if (($t = $request->query('referenceType')) && ($rid = $request->query('referenceId')) && Ids::isUuid((string) $rid)) {
            $q->where('reference_type', (string) $t)->where('reference_id', Ids::toBinary((string) $rid));
        }

        return $this->page($q, $request, 'id', 'desc', Dto::movement(...));
    }

    // ---- rental assets --------------------------------------------------------------------------------------------

    public function rentalAssets(Request $request, RentalService $rentals): JsonResponse
    {
        $q = DB::table('rental_asset')->where('organization_id', Ids::toBinary($this->org()));
        if ($s = $request->query('status')) {
            $q->where('status', (string) $s);
        }
        $this->restrictToVisible($q, $request, 'location_id');

        return $this->page($q, $request, 'id', 'asc', fn ($r) => $rentals->dto($r));
    }

    public function createRentalAsset(Request $request, RentalService $rentals): JsonResponse
    {
        $d = $request->validate(['itemId' => ['required', 'uuid'], 'locationId' => ['required', 'uuid'], 'assetTag' => ['required', 'string', 'max:64']]);
        $loc = $this->access->authorize('inventory.item.manage', $d['locationId']);
        if (! DB::table('inventory_item')->where('id', Ids::toBinary($d['itemId']))->where('organization_id', $loc->organization_id)->exists()) {
            throw ApiProblem::notFound('not_found', 'That inventory item does not exist.');
        }
        $id = Ids::uuid7();
        try {
            DB::transaction(function () use ($d, $id, $loc) {
                DB::table('rental_asset')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => $loc->organization_id, 'item_id' => Ids::toBinary($d['itemId']),
                    'location_id' => Ids::toBinary($d['locationId']), 'asset_tag' => $d['assetTag'],
                ]);
                Audit::record('inventory.rental_asset.create', 'RentalAsset', $id, null, $d, facilityUnitId: $loc->facility_unit_id ? Ids::fromBinary($loc->facility_unit_id) : null);
            });
        } catch (QueryException $e) {
            throw $this->dup($e, 'assetTag', 'An asset with that tag already exists.');
        }

        return response()->json($rentals->dto($this->row('rental_asset', $id)), 201);
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    /** Limit a query to the locations the caller may `inventory.view` (or the one asked for, which must be viewable). */
    private function restrictToVisible($query, Request $request, string $column): void
    {
        if ($l = $request->query('locationId')) {
            $loc = $this->access->locationRow($this->uuid((string) $l));
            if (! $this->access->can('inventory.view', $loc)) {
                throw ApiProblem::permissionDenied('inventory.view');
            }
            $query->where($column, $loc->id);
        } elseif (($visible = $this->access->visibleLocationIds('inventory.view')) !== null) {
            $query->whereIn($column, array_map(Ids::toBinary(...), $visible) ?: [self::NO_ROWS]);
        }
    }

    private function org(): string
    {
        return Tenant::organizationId() ?? throw ApiProblem::unauthenticated();
    }

    private function uuid(string $v): string
    {
        if (! Ids::isUuid($v)) {
            throw ApiProblem::badRequest('validation_failed', 'Malformed identifier.');
        }

        return Ids::normalize($v);
    }

    private function row(string $table, string $id): object
    {
        return DB::table($table)->where('id', Ids::toBinary($id))->first();
    }

    private function dup(QueryException $e, string $field, string $message): \Throwable
    {
        return ($e->errorInfo[1] ?? null) === 1062 ? ApiProblem::unprocessable('validation_failed', $message, [$field => [$message]]) : $e;
    }

    private function page($query, Request $request, string $orderBy, string $dir, callable $map): JsonResponse
    {
        $page = CursorPage::paginate($query, $request, $orderBy, $dir);

        return response()->json(['items' => $page->items->map($map)->values()->all(), 'nextCursor' => $page->nextCursor]);
    }
}
