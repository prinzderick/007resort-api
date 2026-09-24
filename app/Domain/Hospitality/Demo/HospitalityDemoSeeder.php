<?php

namespace App\Domain\Hospitality\Demo;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * DEV-ONLY: KDS stations, prep routing and dining tables for the demo property.
 *
 * Stations reuse the ids of the Organization module's STATION operating points where one exists (MAIN_KITCHEN, RESTAURANT_COUNTER,
 * POOL_BAR, BUSH_BAR), so operating_point.default_prep_station_id, kds_station and channel auth all agree. The Main Kitchen station
 * cooks for the Restaurant, Club, Bush Bar, Pool Bar, Cafe and Event Centre (prep_route_station); every bar prepares its own drinks.
 * Runs after CatalogDemoSeeder (prep routes). Idempotent.
 */
class HospitalityDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 110;
    }

    /** station key => [facility, name, kind, route, id (operating point id or dedicated)] */
    private function stations(): array
    {
        return [
            'MAIN_KITCHEN' => ['MAIN_KITCHEN', 'Main Kitchen Pass', 'KITCHEN', 'KITCHEN', DemoIds::operatingPoint('MAIN_KITCHEN', 'MAIN_KITCHEN')],
            'RESTAURANT_BAR' => ['RESTAURANT', 'Restaurant Bar', 'BAR', 'BAR', DemoIds::operatingPoint('RESTAURANT', 'RESTAURANT_COUNTER')],
            'POOL_BAR' => ['POOL_BAR', 'Pool Bar', 'BAR', 'BAR', DemoIds::operatingPoint('POOL_BAR', 'POOL_BAR')],
            'BUSH_BAR' => ['BUSH_BAR', 'Bush Bar', 'BAR', 'BAR', DemoIds::operatingPoint('BUSH_BAR', 'BUSH_BAR')],
            'CLUB_BAR' => ['INDOOR_CLUB', 'Club Bar', 'BAR', 'BAR', DemoIds::of('kds:INDOOR_CLUB:BAR')],
            'CAFE_BAR' => ['CAFE', 'Cafe Barista', 'BAR', 'BAR', DemoIds::of('kds:CAFE:BAR')],
            'EVENT_BAR' => ['EVENT_CENTRE', 'Event Bar', 'BAR', 'BAR', DemoIds::of('kds:EVENT_CENTRE:BAR')],
        ];
    }

    /** ordering facility => [route => station key] */
    private function routing(): array
    {
        return [
            'RESTAURANT' => ['KITCHEN' => 'MAIN_KITCHEN', 'BAR' => 'RESTAURANT_BAR'],
            'INDOOR_CLUB' => ['KITCHEN' => 'MAIN_KITCHEN', 'BAR' => 'CLUB_BAR'],
            'BUSH_BAR' => ['KITCHEN' => 'MAIN_KITCHEN', 'BAR' => 'BUSH_BAR'],
            'POOL_BAR' => ['KITCHEN' => 'MAIN_KITCHEN', 'BAR' => 'POOL_BAR'],
            'CAFE' => ['KITCHEN' => 'MAIN_KITCHEN', 'BAR' => 'CAFE_BAR'],
            'EVENT_CENTRE' => ['KITCHEN' => 'MAIN_KITCHEN', 'BAR' => 'EVENT_BAR'],
        ];
    }

    /** facility => [label prefix, count, seats, operating point code|null] (a second entry adds VIP/other areas) */
    private function tables(): array
    {
        return [
            'RESTAURANT' => [['T', 10, 4, 'MAIN_DINING'], ['TR', 6, 4, 'TERRACE']],
            'INDOOR_CLUB' => [['C', 8, 4, 'CLUB_FLOOR'], ['VIP', 4, 8, 'CLUB_FLOOR']],
            'BUSH_BAR' => [['BB', 10, 4, 'BUSH_TERRACE']],
            'POOL_BAR' => [['PB', 8, 4, 'POOL_DECK']],
        ];
    }

    public function run(DemoContext $ctx): void
    {
        $orgId = DemoIds::org();
        if (! DB::table('organization')->where('id', Ids::toBinary($orgId))->exists()) {
            $ctx->info('  (organization not seeded; skipping hospitality)');

            return;
        }
        $org = Ids::toBinary($orgId);
        $site = Ids::toBinary(DemoIds::site());
        $stationIds = [];
        foreach ($this->stations() as $key => [$fac, $name, $kind, $route, $id]) {
            if (! DB::table('facility_unit')->where('id', Ids::toBinary(DemoIds::facility($fac)))->exists()) {
                continue;
            }
            DB::table('kds_station')->updateOrInsert(['id' => Ids::toBinary($id)], [
                'organization_id' => $org, 'site_id' => $site, 'facility_unit_id' => Ids::toBinary(DemoIds::facility($fac)),
                'prep_route_id' => Ids::toBinary(DemoIds::of("prep_route:$route")), 'code' => $key, 'name' => $name, 'kind' => $kind, 'is_active' => 1,
            ]);
            $stationIds[$key] = $id;
        }
        foreach ($this->routing() as $fac => $routes) {
            foreach ($routes as $route => $key) {
                if (! isset($stationIds[$key]) || ! DB::table('facility_unit')->where('id', Ids::toBinary(DemoIds::facility($fac)))->exists()) {
                    continue;
                }
                DB::table('prep_route_station')->updateOrInsert(
                    ['facility_unit_id' => Ids::toBinary(DemoIds::facility($fac)), 'prep_route_id' => Ids::toBinary(DemoIds::of("prep_route:$route"))],
                    ['kds_station_id' => Ids::toBinary($stationIds[$key])],
                );
            }
        }
        $tableCount = 0;
        foreach ($this->tables() as $fac => $groups) {
            if (! DB::table('facility_unit')->where('id', Ids::toBinary(DemoIds::facility($fac)))->exists()) {
                continue;
            }
            foreach ($groups as [$prefix, $count, $seats, $opCode]) {
                $op = DB::table('operating_point')->where('id', Ids::toBinary(DemoIds::operatingPoint($fac, $opCode)))->exists() ? Ids::toBinary(DemoIds::operatingPoint($fac, $opCode)) : null;
                for ($i = 1; $i <= $count; $i++) {
                    DB::table('dining_table')->updateOrInsert(['id' => Ids::toBinary(DemoIds::of("table:$fac:$prefix$i"))], [
                        'organization_id' => $org, 'site_id' => $site, 'facility_unit_id' => Ids::toBinary(DemoIds::facility($fac)), 'operating_point_id' => $op,
                        'label' => $prefix.$i, 'seats' => $seats, 'is_active' => 1,
                    ]);
                    $tableCount++;
                }
            }
        }
        $ctx->info('  hospitality: '.count($stationIds).' KDS stations, prep routing, '.$tableCount.' dining tables (Restaurant, Indoor Club, Bush Bar, Pool Bar)');
        $ctx->table('KDS stations (ids equal the operating-point ids where one exists)', ['station', 'facility', 'kind', 'id'], collect($this->stations())->filter(fn ($s, $k) => isset($stationIds[$k]))
            ->map(fn ($s, $k) => [$s[1], $s[0], $s[2], $s[4]])->values()->all());
    }
}
