<?php

namespace App\Domain\Organization\Demo;

use App\Domain\Organization\Models\BookableResource;
use App\Domain\Organization\Models\FacilityUnit;
use App\Domain\Organization\Models\OperatingPoint;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Site;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The Phase-1 property (spec §1.2, architecture/03 + 05): every facility with its capabilities and operating rules (a DATA matrix,
 * never `if (facility == ...)` in code), operating points (counters, table areas, gates, KDS stations) and bookable resources.
 */
class OrganizationDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 10;
    }

    /**
     * code => [name, kind, parentCode|null, [capabilities], [rules: rule_key => value] (attached to the first capability listed for the rule)].
     * Rules with the value `@RECEPTION` resolve to Main Reception's id (payment is taken there: architecture/05 §4).
     */
    private function facilities(): array
    {
        $pos = ['POS', 'PAYMENT_ACCEPTANCE', 'RECEIPT_PRINTING'];
        $svc = ['POS', 'APPOINTMENTS', 'BOOKING', 'STAFF_ASSIGNMENT', 'INVENTORY', 'MEMBERSHIP', 'MEMBER_DISCOUNTS', 'PAYMENT_ACCEPTANCE', 'RECEIPT_PRINTING'];
        $retail = ['POS', 'BARCODE_SALES', 'INVENTORY', 'PAYMENT_ACCEPTANCE', 'RECEIPT_PRINTING'];

        return [
            'RECEPTION' => ['Main Reception', 'RECEPTION', null, array_merge($pos, ['TICKETING', 'BOOKING', 'MEMBERSHIP', 'SUBSCRIPTION_BILLING', 'EQUIPMENT_RENTAL', 'CAPACITY_MANAGEMENT']),
                ['approval_threshold_amount' => ['POS', '10000'], 'require_approval_for' => ['POS', 'order.void,order.discount,order.price_override'], 'require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true'], 'allow_offline_payments' => ['PAYMENT_ACCEPTANCE', 'CASH_ONLY'], 'hold_ttl_seconds' => ['BOOKING', '900']]],
            'RESTAURANT' => ['Restaurant', 'RESTAURANT', null, array_merge($pos, ['TABLE_SERVICE', 'OPEN_TAB', 'KITCHEN_ROUTING', 'INVENTORY']),
                ['approval_threshold_amount' => ['POS', '5000'], 'require_approval_for' => ['POS', 'order.void,order.discount,order.comp'], 'require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true'], 'payment_timing' => ['OPEN_TAB', 'PAY_BEFORE_LEAVING']]],
            'INDOOR_CLUB' => ['Indoor Club', 'CLUB', null, array_merge($pos, ['TABLE_SERVICE', 'OPEN_TAB', 'BAR_ROUTING', 'INVENTORY', 'MEMBERSHIP', 'MEMBER_DISCOUNTS']),
                ['approval_threshold_amount' => ['POS', '5000'], 'require_approval_for' => ['POS', 'order.void,order.discount,order.comp'], 'require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true'], 'payment_timing' => ['OPEN_TAB', 'PAY_ON_EXIT']]],
            'BEAUTY_SPA' => ['Beauty Spa', 'SPA', null, array_merge($svc, ['CAPACITY_MANAGEMENT']),
                ['approval_threshold_amount' => ['POS', '5000'], 'require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true'], 'hold_ttl_seconds' => ['BOOKING', '900']]],
            'POOL_AREA' => ['Pool Area', 'POOL', null, ['TICKET_VALIDATION', 'QR_VALIDATION', 'MEMBERSHIP'],
                ['validation_mode' => ['TICKET_VALIDATION', 'ENTRY_EXIT'], 'payment_facility_unit_id' => ['TICKET_VALIDATION', '@RECEPTION']]],
            'POOL_BAR' => ['Pool Bar', 'BAR', null, ['TABLE_SERVICE', 'OPEN_TAB', 'BAR_ROUTING', 'INVENTORY'],
                ['payment_facility_unit_id' => ['OPEN_TAB', '@RECEPTION'], 'payment_timing' => ['OPEN_TAB', 'PAY_AT_RECEPTION']]],
            'CAFE' => ['Cafe', 'CAFE', null, array_merge($pos, ['INVENTORY']), ['require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true']]],
            'CYBER_CAFE' => ['Cyber Cafe', 'CAFE', null, array_merge($pos, ['INVENTORY', 'BOOKING', 'TIME_SLOTS']), ['slot_granularity_minutes' => ['TIME_SLOTS', '30'], 'require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true']]],
            'MAIN_KITCHEN' => ['Main Kitchen', 'KITCHEN', null, ['KITCHEN_ROUTING', 'INVENTORY'], []],
            'SMALL_KITCHEN' => ['Smaller Kitchen', 'KITCHEN', null, ['INVENTORY'], []],
            'SPORTS_ARENA' => ['Sports Arena', 'SPORTS', null, ['BOOKING', 'TIME_SLOTS', 'CAPACITY_MANAGEMENT', 'TICKET_VALIDATION', 'QR_VALIDATION', 'MEMBERSHIP'],
                ['slot_granularity_minutes' => ['TIME_SLOTS', '60'], 'validation_mode' => ['TICKET_VALIDATION', 'ENTRY'], 'payment_facility_unit_id' => ['BOOKING', '@RECEPTION'], 'hold_ttl_seconds' => ['BOOKING', '900']]],
            'FOOTBALL' => ['Football', 'SPORTS_VENUE', 'SPORTS_ARENA', ['BOOKING', 'TIME_SLOTS', 'CAPACITY_MANAGEMENT'], ['slot_granularity_minutes' => ['TIME_SLOTS', '60'], 'hold_ttl_seconds' => ['BOOKING', '900']]],
            'LAWN_TENNIS' => ['Lawn Tennis', 'SPORTS_VENUE', 'SPORTS_ARENA', ['BOOKING', 'TIME_SLOTS', 'CAPACITY_MANAGEMENT'], ['slot_granularity_minutes' => ['TIME_SLOTS', '60'], 'hold_ttl_seconds' => ['BOOKING', '900']]],
            'BASKETBALL' => ['Basketball', 'SPORTS_VENUE', 'SPORTS_ARENA', ['BOOKING', 'TIME_SLOTS', 'CAPACITY_MANAGEMENT'], ['slot_granularity_minutes' => ['TIME_SLOTS', '60'], 'hold_ttl_seconds' => ['BOOKING', '900']]],
            'BUSH_BAR' => ['Bush Bar', 'BAR', null, array_merge($pos, ['TABLE_SERVICE', 'OPEN_TAB', 'BAR_ROUTING', 'INVENTORY']),
                ['approval_threshold_amount' => ['POS', '5000'], 'require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true'], 'payment_timing' => ['OPEN_TAB', 'PAY_BEFORE_LEAVING']]],
            'EVENT_CENTRE' => ['Event Centre', 'EVENT_CENTRE', null, array_merge($pos, ['OPEN_TAB', 'BAR_ROUTING', 'TICKETING', 'TICKET_VALIDATION', 'BOOKING', 'CAPACITY_MANAGEMENT', 'INVENTORY']), ['require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true'], 'hold_ttl_seconds' => ['BOOKING', '900']]],
            'SALON_MALE' => ['Salon (Male)', 'SALON', null, $svc, ['require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true']]],
            'SALON_FEMALE' => ['Salon (Female)', 'SALON', null, $svc, ['require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true']]],
            'SUPERMARKET' => ['Supermarket', 'RETAIL', null, $retail, ['require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true']]],
            'SUPER_STORE' => ['Super Store', 'RETAIL', null, $retail, ['require_cash_session' => ['PAYMENT_ACCEPTANCE', 'true']]],
            'SPORTS_STORE' => ['Sports Store', 'STORE', null, ['TICKET_VALIDATION', 'QR_VALIDATION', 'EQUIPMENT_RENTAL', 'INVENTORY'], ['validation_mode' => ['TICKET_VALIDATION', 'RELEASE_RETURN'], 'payment_facility_unit_id' => ['TICKET_VALIDATION', '@RECEPTION']]],
            'MAIN_STORE' => ['Main Store', 'STORE', null, ['INVENTORY'], []],
            'ACCOUNTS' => ['Accounts', 'OFFICE', null, [], []],
            'MANAGER_OFFICE' => ['Manager Office', 'OFFICE', null, [], []],
            'IT_OFFICE' => ['IT Office', 'OFFICE', null, [], []],
            'MAIN_GATE' => ['Main Gate', 'GATE', null, [], []],
        ];
    }

    /** facilityCode => [[code, name, kind, defaultPrepStation(operating point code at MAIN_KITCHEN)|null], ...] */
    private function operatingPoints(): array
    {
        return [
            'RECEPTION' => [['COUNTER_1', 'Reception Counter 1', 'COUNTER'], ['COUNTER_2', 'Reception Counter 2', 'COUNTER']],
            'RESTAURANT' => [['RESTAURANT_COUNTER', 'Restaurant Counter', 'STATION'], ['MAIN_DINING', 'Main Dining', 'TABLE_AREA', 'MAIN_KITCHEN'], ['TERRACE', 'Terrace', 'TABLE_AREA', 'MAIN_KITCHEN'], ['CASH_COUNTER', 'Restaurant Cash Counter', 'COUNTER']],
            'INDOOR_CLUB' => [['CLUB_FLOOR', 'Club Floor', 'TABLE_AREA'], ['CLUB_COUNTER', 'Club Counter', 'COUNTER']],
            'BEAUTY_SPA' => [['SPA_COUNTER', 'Spa Counter', 'COUNTER'], ['SPA_ROOMS', 'Treatment Rooms', 'ROOM']],
            'POOL_AREA' => [['POOL_ENTRANCE', 'Pool Entrance', 'GATE']],
            'POOL_BAR' => [['POOL_BAR', 'Pool Bar', 'STATION'], ['POOL_DECK', 'Pool Deck', 'TABLE_AREA', 'POOL_BAR']],
            'CAFE' => [['CAFE_COUNTER', 'Cafe Counter', 'COUNTER']],
            'CYBER_CAFE' => [['CYBER_COUNTER', 'Cyber Cafe Counter', 'COUNTER']],
            'MAIN_KITCHEN' => [['MAIN_KITCHEN', 'Main Kitchen', 'STATION']],
            'SPORTS_ARENA' => [['SPORTS_ENTRANCE', 'Sports Entrance', 'GATE']],
            'BUSH_BAR' => [['BUSH_BAR', 'Bush Bar', 'STATION'], ['BUSH_TERRACE', 'Bush Terrace', 'TABLE_AREA', 'BUSH_BAR']],
            'EVENT_CENTRE' => [['EVENT_ENTRANCE', 'Event Entrance', 'GATE'], ['EVENT_COUNTER', 'Event Counter', 'COUNTER']],
            'SALON_MALE' => [['SALON_M_COUNTER', 'Salon (Male) Counter', 'COUNTER']],
            'SALON_FEMALE' => [['SALON_F_COUNTER', 'Salon (Female) Counter', 'COUNTER']],
            'SUPERMARKET' => [['TILL_1', 'Supermarket Till 1', 'COUNTER'], ['TILL_2', 'Supermarket Till 2', 'COUNTER']],
            'SUPER_STORE' => [['TILL_1', 'Super Store Till 1', 'COUNTER']],
            'SPORTS_STORE' => [['STORE_WINDOW', 'Sports Store Window', 'STORE_WINDOW']],
            'MAIN_STORE' => [['MAIN_STORE_WINDOW', 'Main Store Window', 'STORE_WINDOW']],
            'MAIN_GATE' => [['MAIN_GATE_TURNSTILE', 'Main Gate Attendance Point', 'GATE']],
        ];
    }

    /** facilityCode => [[code, name, capacity], ...] */
    private function resources(): array
    {
        return [
            'FOOTBALL' => [['PITCH_1', 'Football Pitch 1', 1]],
            'LAWN_TENNIS' => [['COURT_1', 'Lawn Tennis Court 1', 1], ['COURT_2', 'Lawn Tennis Court 2', 1]],
            'BASKETBALL' => [['COURT_1', 'Basketball Court 1', 1]],
            'BEAUTY_SPA' => [['ROOM_1', 'Treatment Room 1', 1], ['ROOM_2', 'Treatment Room 2', 1], ['ROOM_3', 'Treatment Room 3', 1]],
            'SALON_MALE' => [['CHAIR_1', 'Male Salon Chair 1', 1], ['CHAIR_2', 'Male Salon Chair 2', 1], ['CHAIR_3', 'Male Salon Chair 3', 1]],
            'SALON_FEMALE' => [['CHAIR_1', 'Female Salon Chair 1', 1], ['CHAIR_2', 'Female Salon Chair 2', 1], ['CHAIR_3', 'Female Salon Chair 3', 1], ['CHAIR_4', 'Female Salon Chair 4', 1]],
            'CYBER_CAFE' => array_map(fn ($i) => [sprintf('PC_%02d', $i), sprintf('Cyber PC %02d', $i), 1], range(1, 10)),
            'EVENT_CENTRE' => [['HALL', 'Event Hall', 300]],
        ];
    }

    public function run(DemoContext $ctx): void
    {
        $org = Organization::query()->updateOrCreate(['id' => DemoIds::org()], ['name' => '007 Resort & Spa (demo organization)']);
        $site = Site::query()->updateOrCreate(['id' => DemoIds::site()], [
            'organization_id' => $org->id, 'name' => '007 Resort & Spa', 'time_zone' => 'Africa/Lagos', 'address' => 'Otueke, Bayelsa State, Nigeria', 'currency' => 'NGN',
        ]);

        $base = now('UTC')->subDay();
        $i = 0;
        foreach ($this->facilities() as $code => [$name, $kind, $parent]) {
            $f = FacilityUnit::query()->firstOrNew(['id' => DemoIds::facility($code)]);
            $f->forceFill([
                'organization_id' => $org->id, 'site_id' => $site->id, 'parent_id' => $parent ? DemoIds::facility($parent) : null,
                'code' => $code, 'name' => $name, 'kind' => $kind, 'is_active' => 1,
            ]);
            if (! $f->exists) {
                $f->created_at = $base->addMilliseconds(++$i * 10); // stable display order
            }
            $f->save();
        }
        // parents must exist before children reference them (self FK): second pass fixes ordering issues on a fresh DB
        foreach ($this->facilities() as $code => [, , $parent]) {
            if ($parent) {
                DB::table('facility_unit')->where('id', Ids::toBinary(DemoIds::facility($code)))->update(['parent_id' => Ids::toBinary(DemoIds::facility($parent))]);
            }
        }

        $this->capabilities($org->id);
        $this->operatingPointsAndResources($org->id, $site->id);
        $ctx->info('  '.count($this->facilities()).' facilities, capabilities + operating rules, operating points (incl. 4 KDS stations), bookable resources');
    }

    private function capabilities(string $orgId): void
    {
        $reception = DemoIds::facility('RECEPTION');
        foreach ($this->facilities() as $code => [, , , $caps, $rules]) {
            $facilityBin = Ids::toBinary(DemoIds::facility($code));
            $capIds = [];
            foreach ($caps as $cap) {
                $existing = DB::table('facility_capability')->where('facility_unit_id', $facilityBin)->where('capability_code', $cap)->first();
                $id = $existing ? $existing->id : Ids::toBinary(Ids::uuid7());
                if (! $existing) {
                    DB::table('facility_capability')->insert(['id' => $id, 'facility_unit_id' => $facilityBin, 'capability_code' => $cap, 'is_enabled' => 1]);
                } else {
                    DB::table('facility_capability')->where('id', $id)->update(['is_enabled' => 1]);
                }
                $capIds[$cap] = $id;
            }
            foreach ($rules as $key => [$cap, $value]) {
                $value = $value === '@RECEPTION' ? $reception : $value;
                DB::table('operating_rule')->updateOrInsert(
                    ['facility_capability_id' => $capIds[$cap], 'rule_key' => $key],
                    ['id' => Ids::toBinary(Ids::uuid7()), 'rule_value' => $value],
                );
            }
        }
    }

    private function operatingPointsAndResources(string $orgId, string $siteId): void
    {
        foreach ($this->operatingPoints() as $facilityCode => $points) {
            foreach ($points as $p) {
                [$code, $name, $kind] = $p;
                $station = $p[3] ?? null; // default prep station operating point (code == its facility code)
                OperatingPoint::query()->updateOrCreate(['id' => DemoIds::operatingPoint($facilityCode, $code)], [
                    'organization_id' => $orgId, 'site_id' => $siteId, 'facility_unit_id' => DemoIds::facility($facilityCode),
                    'code' => $code, 'name' => $name, 'kind' => $kind,
                    'default_prep_station_id' => $station ? DemoIds::operatingPoint($station === 'POOL_BAR' ? 'POOL_BAR' : ($station === 'BUSH_BAR' ? 'BUSH_BAR' : 'MAIN_KITCHEN'), $station) : null,
                    'is_active' => 1,
                ]);
            }
        }
        foreach ($this->resources() as $facilityCode => $rows) {
            foreach ($rows as [$code, $name, $capacity]) {
                BookableResource::query()->updateOrCreate(['id' => DemoIds::resource($facilityCode, $code)], [
                    'organization_id' => $orgId, 'site_id' => $siteId, 'facility_unit_id' => DemoIds::facility($facilityCode),
                    'code' => $code, 'name' => $name, 'capacity' => $capacity, 'is_active' => 1,
                ]);
            }
        }
    }
}
