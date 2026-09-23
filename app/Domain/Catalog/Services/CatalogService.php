<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Contracts\StockLevelProvider;
use App\Domain\Hospitality\Services\StationRouter;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read side of the catalog (resolved per facility) + availability. Admin writes live in CatalogAdmin. */
final class CatalogService
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly StationRouter $router,
        private readonly StockLevelProvider $stock,
    ) {}

    /** Contract Product.kind derived from the commercial kind + prep route. */
    public static function contractKind(string $kind, string $routeKind): string
    {
        return match ($kind) {
            'GOOD' => match ($routeKind) {
                'KITCHEN' => 'FOOD', 'BAR' => 'DRINK', default => 'RETAIL'
            },
            'TICKET', 'RENTAL', 'MEMBERSHIP' => $kind,
            default => 'SERVICE', // SERVICE, FEE
        };
    }

    /** Base product query joined with route + (facility) availability row. */
    public function productQuery(string $organizationId, string $facilityId): Builder
    {
        return DB::table('product as p')
            ->join('product_facility as pf', fn ($j) => $j->on('pf.product_id', '=', 'p.id')->where('pf.facility_unit_id', Ids::toBinary($facilityId)))
            ->leftJoin('prep_route as pr', 'pr.id', '=', 'p.prep_route_id')
            ->where('p.organization_id', Ids::toBinary($organizationId))
            ->whereNull('p.deleted_at')
            ->select(['p.*', 'pf.is_available', 'pf.unavailable_reason', 'pf.kds_station_id', 'pr.kind as route_kind', 'pr.name as route_name']);
    }

    /** Apply contract filters (kind maps back to commercial kind/route). */
    public function applyKindFilter(Builder $q, string $contractKind): void
    {
        match ($contractKind) {
            'FOOD' => $q->where('p.kind', 'GOOD')->where('pr.kind', 'KITCHEN'),
            'DRINK' => $q->where('p.kind', 'GOOD')->where('pr.kind', 'BAR'),
            'RETAIL' => $q->where('p.kind', 'GOOD')->where(fn ($w) => $w->whereNull('pr.kind')->orWhere('pr.kind', 'NONE')),
            'TICKET', 'RENTAL', 'MEMBERSHIP' => $q->where('p.kind', $contractKind),
            'SERVICE' => $q->whereIn('p.kind', ['SERVICE', 'FEE']),
            default => throw ApiProblem::unprocessable('validation_failed', 'Unknown product kind filter.'),
        };
    }

    /**
     * @param  iterable<object>  $rows  from productQuery()
     * @return list<array<string, mixed>>
     */
    public function presentMany(iterable $rows, string $organizationId, string $facilityId): array
    {
        $rows = collect($rows)->values();
        $prices = $this->pricing->unitPrices($rows->map(fn ($r) => Ids::fromBinary($r->id))->all(), $facilityId);
        $setting = $this->pricing->setting($organizationId);

        return $rows->map(function ($r) use ($prices, $setting, $facilityId): array {
            $id = Ids::fromBinary($r->id);
            $price = $prices[$id] ?? null;
            $rate = $this->pricing->rateFor($r, $setting);
            $tax = $price === null ? '0.0000' : Pricing::line($price, 1, $rate, $setting['pricesTaxInclusive'])['tax'];
            $station = $r->route_kind && $r->route_kind !== 'NONE' ? $this->router->stationFor($facilityId, $r->prep_route_id, $r->kds_station_id) : null;

            return [
                'id' => $id,
                'sku' => $r->sku,
                'name' => $r->name,
                'categoryId' => Ids::fromBinary($r->category_id),
                'kind' => self::contractKind($r->kind, $r->route_kind ?? 'NONE'),
                'commercialKind' => $r->kind,
                'price' => $price ?? '0.0000',
                'currency' => 'NGN',
                'taxInclusive' => $setting['pricesTaxInclusive'],
                'taxRatePercent' => $rate,
                'taxAmount' => $tax,
                'prepRoute' => array_filter([
                    'stationId' => $station ? Ids::fromBinary($station->id) : null,
                    'stationName' => $station?->name,
                    'kind' => $r->route_kind ?? 'NONE',
                ], fn ($v) => $v !== null),
                'trackStock' => (bool) $r->track_stock,
                // active = the product is live AND not 86'd at this facility (clients hide inactive items)
                'active' => (bool) $r->is_active && (bool) $r->is_available && $price !== null,
                'imageUrl' => $r->image_url,
                'rowVersion' => (int) $r->row_version,
            ];
        })->all();
    }

    /** @return array{available: bool, reason: ?string, quantityOnHand: ?string} */
    public function availability(object $row, string $facilityId): array
    {
        $qty = $this->stock->onHand(Ids::fromBinary($row->id), $facilityId);
        if (! $row->is_active) {
            return ['available' => false, 'reason' => 'INACTIVE', 'quantityOnHand' => $qty];
        }
        if (! $row->is_available) {
            return ['available' => false, 'reason' => $row->unavailable_reason ?: 'MANUALLY_DISABLED', 'quantityOnHand' => $qty];
        }
        if ($row->track_stock && $qty !== null && bccomp($qty, '0', 4) <= 0) {
            return ['available' => false, 'reason' => 'OUT_OF_STOCK', 'quantityOnHand' => $qty];
        }

        return ['available' => true, 'reason' => null, 'quantityOnHand' => $qty];
    }

    /** 86 / un-86 a product at a facility. */
    public function setAvailability(string $productId, string $facilityId, bool $available, ?string $reason): array
    {
        return DB::transaction(function () use ($productId, $facilityId, $available, $reason) {
            $pf = DB::table('product_facility')->where('product_id', Ids::toBinary($productId))->where('facility_unit_id', Ids::toBinary($facilityId))->lockForUpdate()->first();
            if ($pf === null) {
                throw ApiProblem::notFound('not_found', 'That product is not sold at this facility.');
            }
            DB::table('product_facility')->where('product_id', $pf->product_id)->where('facility_unit_id', $pf->facility_unit_id)->update([
                'is_available' => $available ? 1 : 0,
                'unavailable_reason' => $available ? null : 'MANUALLY_DISABLED',
                'row_version' => $pf->row_version + 1,
                'updated_at' => Fmt::now(),
            ]);
            DB::table('product')->where('id', $pf->product_id)->update(['updated_at' => Fmt::now()]); // bumps updatedSince caches
            Audit::record('catalog.availability.set', 'Product', $productId,
                old: ['available' => (bool) $pf->is_available], new: ['available' => $available, 'reason' => $reason],
                facilityUnitId: $facilityId);

            $row = $this->productQuery(Tenant::organizationId(), $facilityId)->where('p.id', $pf->product_id)->first();

            return ['productId' => $productId, 'facilityId' => $facilityId] + $this->availability($row, $facilityId);
        });
    }
}
