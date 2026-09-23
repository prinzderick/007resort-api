<?php

namespace App\Domain\Hospitality\Services;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Resolves which KDS station prepares a product at a facility (product_facility override, else the station serving the prep route). */
final class StationRouter
{
    /** @var array<string, ?object> */
    private array $cache = [];

    /** @return ?object station row (id, name, kind) or null when nothing is routed */
    public function stationFor(string $facilityId, ?string $prepRouteIdBin, ?string $overrideStationBin = null): ?object
    {
        if ($overrideStationBin !== null) {
            $k = 's:'.bin2hex($overrideStationBin);

            return $this->cache[$k] ??= DB::table('kds_station')->where('id', $overrideStationBin)->where('is_active', 1)->first(['id', 'name', 'kind']);
        }
        if ($prepRouteIdBin === null) {
            return null;
        }
        $k = 'r:'.$facilityId.bin2hex($prepRouteIdBin);

        return $this->cache[$k] ??= $this->mapped($facilityId, $prepRouteIdBin) ?? (DB::table('kds_station')
            ->where('facility_unit_id', Ids::toBinary($facilityId))->where('prep_route_id', $prepRouteIdBin)->where('is_active', 1)
            ->orderBy('id')->first(['id', 'name', 'kind']) ?: null);
    }

    /** Explicit facility+route -> station mapping (station may be in another facility, e.g. Restaurant -> Main Kitchen). */
    private function mapped(string $facilityId, string $prepRouteIdBin): ?object
    {
        return DB::table('prep_route_station as m')->join('kds_station as s', 's.id', '=', 'm.kds_station_id')
            ->where('m.facility_unit_id', Ids::toBinary($facilityId))->where('m.prep_route_id', $prepRouteIdBin)->where('s.is_active', 1)
            ->first(['s.id', 's.name', 's.kind']) ?: null;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
