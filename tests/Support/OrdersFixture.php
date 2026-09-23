<?php

namespace Tests\Support;

use App\Domain\Identity\Models\Staff;
use App\Domain\Organization\Models\FacilityUnit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Restaurant + club fixture for the orders/KDS/approval tests (real MySQL). */
final class OrdersFixture
{
    public array $t;

    public FacilityUnit $restaurant;

    public FacilityUnit $club;

    /** @var array<string, string> label => id */
    public array $tables = [];

    /** @var array<string, string> */
    public array $products = [];

    public string $kitchenStation;

    public string $barStation;

    public string $kitchenRoute;

    public string $barRoute;

    /** @var array<string, Staff> */
    public array $staff = [];

    public static function make(): self
    {
        $f = new self;
        $f->t = TestData::tenant();
        $f->restaurant = TestData::facility($f->t, 'rst1');
        $f->club = TestData::facility($f->t, 'club');
        $org = Ids::toBinary($f->t['org']);
        $site = Ids::toBinary($f->t['site']);

        foreach (['T1', 'T2', 'T3'] as $label) {
            $id = Ids::uuid7();
            DB::table('dining_table')->insert(['id' => Ids::toBinary($id), 'organization_id' => $org, 'site_id' => $site, 'facility_unit_id' => Ids::toBinary($f->restaurant->id), 'label' => $label, 'seats' => 4]);
            $f->tables[$label] = $id;
        }
        $id = Ids::uuid7();
        DB::table('dining_table')->insert(['id' => Ids::toBinary($id), 'organization_id' => $org, 'site_id' => $site, 'facility_unit_id' => Ids::toBinary($f->club->id), 'label' => 'VIP1', 'seats' => 6]);
        $f->tables['VIP1'] = $id;

        $f->kitchenRoute = self::insert('prep_route', ['organization_id' => $org, 'code' => 'KITCHEN', 'name' => 'Kitchen', 'kind' => 'KITCHEN']);
        $f->barRoute = self::insert('prep_route', ['organization_id' => $org, 'code' => 'BAR', 'name' => 'Bar', 'kind' => 'BAR']);
        $f->kitchenStation = self::insert('kds_station', ['organization_id' => $org, 'site_id' => $site, 'facility_unit_id' => Ids::toBinary($f->restaurant->id), 'prep_route_id' => Ids::toBinary($f->kitchenRoute), 'code' => 'KITCHEN', 'name' => 'Kitchen Pass', 'kind' => 'KITCHEN']);
        $f->barStation = self::insert('kds_station', ['organization_id' => $org, 'site_id' => $site, 'facility_unit_id' => Ids::toBinary($f->restaurant->id), 'prep_route_id' => Ids::toBinary($f->barRoute), 'code' => 'BAR', 'name' => 'Bar', 'kind' => 'BAR']);

        $cat = self::insert('product_category', ['organization_id' => $org, 'name' => 'Menu']);
        $list = self::insert('price_list', ['organization_id' => $org, 'name' => 'Standard', 'is_default' => 1]);
        $f->product('jollof', 'Jollof Rice & Chicken', '4500', $f->kitchenRoute, $cat, $list);
        $f->product('suya', 'Beef Suya', '3000', $f->kitchenRoute, $cat, $list);
        $f->product('chapman', 'Chapman', '2500', $f->barRoute, $cat, $list);
        $f->product('water', 'Water 75cl', '500', null, $cat, $list);

        $f->staff['waiter'] = TestData::staff($f->t, 'waiter');
        TestData::assign($f->staff['waiter'], 'WAIT_STAFF', 'FACILITY_UNIT', $f->restaurant->id);
        $f->staff['waiter2'] = TestData::staff($f->t, 'waiter2');
        TestData::assign($f->staff['waiter2'], 'WAIT_STAFF', 'FACILITY_UNIT', $f->restaurant->id);
        $f->staff['chef'] = TestData::staff($f->t, 'chef');
        TestData::assign($f->staff['chef'], 'KITCHEN_STAFF', 'FACILITY_UNIT', $f->restaurant->id);
        $f->staff['barman'] = TestData::staff($f->t, 'barman');
        TestData::assign($f->staff['barman'], 'BARTENDER', 'FACILITY_UNIT', $f->restaurant->id);
        $f->staff['supervisor'] = TestData::staff($f->t, 'supervisor');
        TestData::assign($f->staff['supervisor'], 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $f->restaurant->id);
        $f->staff['manager'] = TestData::staff($f->t, 'manager');
        TestData::assign($f->staff['manager'], 'MANAGER', 'SITE');
        // A role literally named "Manager" that carries NO approval permissions.
        $f->staff['fakemanager'] = TestData::staff($f->t, 'fakemanager');
        TestData::assignRole($f->staff['fakemanager'], TestData::customRole('MGR_LOOKALIKE', 'Manager', ['order.create', 'order.view', 'order.line.add', 'order.send']), 'SITE');

        return $f;
    }

    private function product(string $key, string $name, string $price, ?string $route, string $cat, string $list): void
    {
        $id = self::insert('product', [
            'organization_id' => Ids::toBinary($this->t['org']), 'category_id' => Ids::toBinary($cat), 'sku' => strtoupper($key), 'name' => $name,
            'kind' => 'GOOD', 'prep_route_id' => $route ? Ids::toBinary($route) : null,
        ]);
        DB::table('product_facility')->insert(['product_id' => Ids::toBinary($id), 'facility_unit_id' => Ids::toBinary($this->restaurant->id)]);
        self::insert('price', ['price_list_id' => Ids::toBinary($list), 'product_id' => Ids::toBinary($id), 'amount' => $price]);
        $this->products[$key] = $id;
    }

    /** @param array<string, mixed> $row @return string canonical id */
    public static function insert(string $table, array $row): string
    {
        $id = Ids::uuid7();
        DB::table($table)->insert(['id' => Ids::toBinary($id)] + $row);

        return $id;
    }

    /** Enable a capability + optional operating rules at a facility. @param array<string, string> $rules */
    public function capability(FacilityUnit $facility, string $code, array $rules = []): void
    {
        $fcId = self::insert('facility_capability', ['facility_unit_id' => Ids::toBinary($facility->id), 'capability_code' => $code, 'is_enabled' => 1]);
        foreach ($rules as $k => $v) {
            self::insert('operating_rule', ['facility_capability_id' => Ids::toBinary($fcId), 'rule_key' => $k, 'rule_value' => $v]);
        }
    }

    public function setVat(bool $enabled, string $rate = '7.5', bool $inclusive = true): void
    {
        DB::table('organization_tax_setting')->updateOrInsert(['organization_id' => Ids::toBinary($this->t['org'])], [
            'vat_enabled' => $enabled ? 1 : 0, 'vat_rate_percent' => $rate, 'prices_tax_inclusive' => $inclusive ? 1 : 0,
        ]);
    }
}
