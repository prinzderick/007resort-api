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

        return $this->cache[$k] ??= DB::table('kds_station')
            ->where('facility_unit_id', Ids::toBinary($facilityId))->where('prep_route_id', $prepRouteIdBin)->where('is_active', 1)
            ->orderBy('id')->first(['id', 'name', 'kind']) ?: null;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
