<?php

namespace App\Domain\Customer\Services;

use App\Domain\Catalog\Services\CatalogService;
use App\Domain\Customer\Support\TicketCatalog;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The public ticket catalogue. A ticket is SOLD at a facility (product_facility, e.g. Reception) but VALID at another (the
 * ticket type's facility, e.g. the pool). `facilityId` on the public API is the facility the ticket gives access to; the selling
 * facility is resolved here (the given facility itself when it sells the product, otherwise the first that does).
 */
class PublicCatalog
{
    public function __construct(private readonly CatalogService $catalog) {}

    /** @return list<string> product ids (canonical) of active ticket products for an access facility */
    public function ticketProductIds(string $orgId, string $facilityId): array
    {
        $bin = Ids::toBinary($facilityId);
        $viaType = DB::table('ticket_type as t')->join('product as p', 'p.id', '=', 't.product_id')->where('t.facility_unit_id', $bin)->where('t.is_active', 1)->pluck('p.id');
        $direct = DB::table('product_facility as pf')->join('product as p', 'p.id', '=', 'pf.product_id')->where('pf.facility_unit_id', $bin)->where('p.kind', 'TICKET')->pluck('p.id');

        return $viaType->merge($direct)->unique()->filter(fn ($id) => DB::table('product')->where('id', $id)->where('kind', 'TICKET')->where('is_active', 1)->where('organization_id', Ids::toBinary($orgId))->whereNull('deleted_at')->exists())
            ->map(fn ($b) => Ids::fromBinary($b))->values()->all();
    }

    public function sellFacility(string $productId, string $accessFacilityId): ?string
    {
        $rows = DB::table('product_facility')->where('product_id', Ids::toBinary($productId))->where('is_available', 1)->pluck('facility_unit_id')->map(fn ($b) => Ids::fromBinary($b))->all();

        return in_array($accessFacilityId, $rows, true) ? $accessFacilityId : ($rows[0] ?? null);
    }

    /** @return array{items: list<array<string, mixed>>, nextCursor: null} */
    public function tickets(string $orgId, string $facilityId): array
    {
        $bySell = [];
        foreach ($this->ticketProductIds($orgId, $facilityId) as $pid) {
            if ($sell = $this->sellFacility($pid, $facilityId)) {
                $bySell[$sell][] = $pid;
            }
        }
        $items = [];
        foreach ($bySell as $sell => $ids) {
            $rows = $this->catalog->productQuery($orgId, $sell)->whereIn('p.id', array_map(Ids::toBinary(...), $ids))->orderBy('p.name')->get();
            foreach ($this->catalog->presentMany($rows, $orgId, $sell) as $p) {
                $items[] = TicketCatalog::publicView($p) + ['facilityId' => $facilityId];
            }
        }
        usort($items, fn ($a, $b) => [$a['ticketCategory'] ?? '', $a['name']] <=> [$b['ticketCategory'] ?? '', $b['name']]);

        return ['items' => $items, 'nextCursor' => null];
    }
}
