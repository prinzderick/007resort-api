<?php

namespace Tests\Feature\Organization;

use App\Domain\Organization\Services\CapabilityService;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoIds;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\DemoApi;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use DemoApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_site_and_facility_tree(): void
    {
        $api = $this->api('wait1');
        $api->get('/organization/site')->assertOk()->assertJson(['id' => DemoIds::site(), 'name' => '007 Resort & Spa', 'timezone' => 'Africa/Lagos', 'currency' => 'NGN']);

        $tree = $api->get('/organization/facilities')->assertOk()->json('items');
        $byCode = collect($tree)->keyBy('code');
        $this->assertCount(23, $tree, 'root facilities');
        $this->assertSame('RECEPTION', $tree[0]['code'], 'display order is stable (reception first)');
        $arena = $byCode['SPORTS_ARENA'];
        $this->assertEqualsCanonicalizing(['FOOTBALL', 'LAWN_TENNIS', 'BASKETBALL'], collect($arena['children'])->pluck('code')->all());
        $this->assertSame($arena['id'], $arena['children'][0]['parentId']);
        $this->assertSame([], $byCode['RESTAURANT']['children']);
        $this->assertContains('OPEN_TAB', $byCode['RESTAURANT']['capabilities']);
        $this->assertSame('ACTIVE', $byCode['RESTAURANT']['status']);
        $this->assertGreaterThanOrEqual(26, DB::table('facility_unit')->count());

        $one = $api->get('/organization/facilities/'.$this->facilityId('BASKETBALL'))->assertOk()->assertJsonPath('parentId', $arena['id'])->assertHeader('ETag');
        $this->assertSame('BASKETBALL', $one->json('code'));
        $api->get('/organization/facilities/'.Ids::uuid7())->assertStatus(404)->assertJsonPath('code', 'not_found');
        $api->get('/organization/facilities/nope')->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/organization/facilities')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_effective_capabilities_and_operating_rules(): void
    {
        $api = $this->api('wait1');

        $rest = $api->get('/facilities/'.$this->facilityId('RESTAURANT').'/capabilities')->assertOk();
        $this->assertSame($this->facilityId('RESTAURANT'), $rest->json('facilityId'));
        $this->assertEqualsCanonicalizing(['POS', 'PAYMENT_ACCEPTANCE', 'RECEIPT_PRINTING', 'TABLE_SERVICE', 'OPEN_TAB', 'KITCHEN_ROUTING', 'INVENTORY'], $rest->json('capabilities'));
        $rules = $rest->json('operatingRules');
        $this->assertSame('5000.0000', $rules['approvalThresholdAmount']);
        $this->assertSame(['order.void', 'order.discount', 'order.comp'], $rules['requireApprovalFor']);
        $this->assertTrue($rules['allowOpenTabs']);
        $this->assertTrue($rules['requireCashSession']);
        $this->assertSame('CASH_ONLY', $rules['allowOfflinePayments']);
        $this->assertSame(900, $rules['holdTtlSeconds']);
        $this->assertFalse($rules['vatEnabled']);
        $this->assertSame('7.5', $rules['vatRatePercent']);
        $this->assertSame('PAY_BEFORE_LEAVING', $rules['paymentTiming']);

        // Pool Bar has no POS/terminal: payment is routed to Reception (architecture/05 §4)
        $bar = $api->get('/facilities/'.$this->facilityId('POOL_BAR').'/capabilities')->json();
        $this->assertNotContains('POS', $bar['capabilities']);
        $this->assertNotContains('PAYMENT_ACCEPTANCE', $bar['capabilities']);
        $this->assertSame('NONE', $bar['operatingRules']['allowOfflinePayments']);
        $this->assertSame($this->facilityId('RECEPTION'), $bar['operatingRules']['paymentFacilityUnitId']);

        $arena = $api->get('/facilities/'.$this->facilityId('SPORTS_ARENA').'/capabilities')->json('operatingRules');
        $this->assertSame(60, $arena['slotGranularityMinutes']);
        $this->assertSame('ENTRY', $arena['validationMode']);
        $this->assertSame('PAY_ON_EXIT', $api->get('/facilities/'.$this->facilityId('INDOOR_CLUB').'/capabilities')->json('operatingRules.paymentTiming'));
        $this->assertSame(30, $api->get('/facilities/'.$this->facilityId('CYBER_CAFE').'/capabilities')->json('operatingRules.slotGranularityMinutes'));
        $this->assertSame([], $api->get('/facilities/'.$this->facilityId('ACCOUNTS').'/capabilities')->json('capabilities'));
        $api->get('/facilities/'.Ids::uuid7().'/capabilities')->assertStatus(404);
    }

    public function test_capability_service_answers_from_data_not_names(): void
    {
        $caps = app(CapabilityService::class);
        $this->assertTrue($caps->has($this->facilityId('BUSH_BAR'), 'BAR_ROUTING'));
        $this->assertFalse($caps->has($this->facilityId('BUSH_BAR'), 'TICKETING'));
        $caps->requireCapability($this->facilityId('RESTAURANT'), 'KITCHEN_ROUTING');
        try {
            $caps->requireCapability($this->facilityId('RESTAURANT'), 'TICKETING');
            $this->fail('expected capability_disabled');
        } catch (ApiProblem $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('capability_disabled', $e->problemCode);
        }
        // turning a capability off is pure data
        DB::table('facility_capability')->where('facility_unit_id', hex2bin(str_replace('-', '', $this->facilityId('RESTAURANT'))))->where('capability_code', 'OPEN_TAB')->update(['is_enabled' => 0]);
        $this->assertFalse($caps->rules($this->facilityId('RESTAURANT'))['allowOpenTabs']);
    }

    public function test_operating_points_are_paginated_and_include_kds_stations(): void
    {
        $api = $this->api('wait1');
        $page1 = $api->get('/organization/facilities/'.$this->facilityId('RESTAURANT').'/operating-points?limit=2')->assertOk();
        $this->assertCount(2, $page1->json('items'));
        $this->assertNotNull($page1->json('nextCursor'));
        $page2 = $api->get('/organization/facilities/'.$this->facilityId('RESTAURANT').'/operating-points?limit=2&cursor='.$page1->json('nextCursor'))->assertOk();
        $codes = array_merge(array_column($page1->json('items'), 'code'), array_column($page2->json('items'), 'code'));
        $this->assertSame(['CASH_COUNTER', 'MAIN_DINING', 'RESTAURANT_COUNTER', 'TERRACE'], $codes);
        $this->assertNull($page2->json('nextCursor'));
        $this->assertSame('STATION', collect($page1->json('items'))->merge($page2->json('items'))->firstWhere('code', 'RESTAURANT_COUNTER')['kind']);

        $stations = DB::table('operating_point')->where('kind', 'STATION')->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Bush Bar', 'Main Kitchen', 'Pool Bar', 'Restaurant Counter'], $stations);
        $dining = collect($page1->json('items'))->merge($page2->json('items'))->firstWhere('code', 'MAIN_DINING');
        $this->assertSame(DemoIds::operatingPoint('MAIN_KITCHEN', 'MAIN_KITCHEN'), $dining['defaultPrepStationId']);
    }

    public function test_bookable_resources_and_check_constraints(): void
    {
        $this->assertSame(10, DB::table('bookable_resource')->where('facility_unit_id', hex2bin(str_replace('-', '', $this->facilityId('CYBER_CAFE'))))->count());
        $this->assertSame(2, DB::table('bookable_resource')->where('facility_unit_id', hex2bin(str_replace('-', '', $this->facilityId('LAWN_TENNIS'))))->count());

        DB::beginTransaction();
        try {
            DB::table('operating_point')->insert([
                'id' => random_bytes(16), 'organization_id' => hex2bin(str_replace('-', '', DemoIds::org())), 'site_id' => hex2bin(str_replace('-', '', DemoIds::site())),
                'facility_unit_id' => hex2bin(str_replace('-', '', $this->facilityId('CAFE'))), 'code' => 'X', 'name' => 'X', 'kind' => 'BOGUS',
            ]);
            $this->fail('CHECK constraint on operating_point.kind must be enforced');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_tax_setting_defaults_off_and_is_admin_settable_with_audit(): void
    {
        $it = $this->api('itadmin1');
        $get = $it->get('/admin/settings/tax')->assertOk();
        $this->assertSame(['vatEnabled' => false, 'vatRatePercent' => '7.5', 'pricesTaxInclusive' => false, 'vatNumber' => null, 'rowVersion' => 0], $get->json());
        $this->assertSame('"0"', $get->headers->get('ETag'));

        // needs If-Match (428) and the right version (412)
        $it->put('/admin/settings/tax', ['vatEnabled' => false], ['Idempotency-Key' => 'tax-key-000000001'])->assertStatus(428)->assertJsonPath('code', 'concurrency_conflict');
        $it->put('/admin/settings/tax', ['vatEnabled' => false], ['Idempotency-Key' => 'tax-key-000000002', 'If-Match' => '"5"'])->assertStatus(412);

        // VAT on requires a TIN
        $it->put('/admin/settings/tax', ['vatEnabled' => true], ['Idempotency-Key' => 'tax-key-000000003', 'If-Match' => '"0"'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $put = $it->put('/admin/settings/tax', ['vatEnabled' => true, 'vatRatePercent' => '7.5', 'vatNumber' => '12345678-0001', 'pricesTaxInclusive' => true], ['Idempotency-Key' => 'tax-key-000000004', 'If-Match' => '"0"'])->assertOk();
        $this->assertTrue($put->json('vatEnabled'));
        $this->assertSame(1, $put->json('rowVersion'));
        $this->assertSame('12345678-0001', $put->json('vatNumber'));

        // replay with same key returns original; stale version with a new key is 412
        $it->put('/admin/settings/tax', ['vatEnabled' => true, 'vatRatePercent' => '7.5', 'vatNumber' => '12345678-0001', 'pricesTaxInclusive' => true], ['Idempotency-Key' => 'tax-key-000000004', 'If-Match' => '"0"'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $it->put('/admin/settings/tax', ['vatRatePercent' => '5'], ['Idempotency-Key' => 'tax-key-000000005', 'If-Match' => '"0"'])->assertStatus(412);
        $it->put('/admin/settings/tax', ['vatRatePercent' => '5'], ['Idempotency-Key' => 'tax-key-000000006', 'If-Match' => '"1"'])->assertOk()->assertJsonPath('vatRatePercent', '5')->assertJsonPath('rowVersion', 2);
        $it->put('/admin/settings/tax', ['vatRatePercent' => '101'], ['Idempotency-Key' => 'tax-key-000000007', 'If-Match' => '"2"'])->assertStatus(422);

        // feeds effective operating rules + system info
        $this->assertTrue($this->api('wait1')->get('/facilities/'.$this->facilityId('RESTAURANT').'/capabilities')->json('operatingRules.vatEnabled'));
        $this->getJson('/api/v1/system/info')->assertJsonPath('vatEnabled', true);

        $audits = DB::table('audit_log')->where('action', 'tax_setting.update')->orderBy('seq')->get();
        $this->assertCount(2, $audits);
        $this->assertSame('false', json_encode(json_decode($audits[0]->old_value)->vatEnabled));
        $this->assertTrue(Audit::verifyChain()->valid);
        $this->assertSame(2, DB::table('outbox_event')->where('event_type', 'ConfigurationUpdated')->count(), 'tax setting changes are outbox events');
    }

    public function test_tax_setting_requires_config_manage_and_is_not_role_name_based(): void
    {
        $this->api('cashier1')->get('/admin/settings/tax')->assertStatus(403)->assertJsonPath('permission', 'config.manage');
        $this->api('manager1')->get('/admin/settings/tax')->assertStatus(403); // manager bundle has no config.manage (contract: permission based)
        $this->api('owner1')->get('/admin/settings/tax')->assertOk();
    }
}
