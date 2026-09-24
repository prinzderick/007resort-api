<?php

namespace Tests\Feature\Config;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class SearchAndAuditTest extends ConfigTestCase
{
    public function test_search_is_permissioned_per_result_type(): void
    {
        $o = $this->owner();
        $all = $o->get('/admin/search?q=an&limit=3')->assertOk()->json('results');
        $this->assertArrayHasKey('staff', $all);
        $this->assertArrayHasKey('product', $all);
        $this->assertArrayHasKey('facility', $all);
        $this->assertArrayHasKey('customer', $all);

        $staff = $o->get('/admin/search?q=Amaka&types=staff')->json('results.staff');
        $this->assertSame('Amaka Okoro', $staff[0]['title']);
        $this->assertSame(Ids::normalize(DemoIds::staff('wait1')), $staff[0]['id']);
        $prod = $o->get('/admin/search?q=Sliced&types=product')->json('results.product');
        $this->assertSame('BK-BRED', explode(' ', $prod[0]['subtitle'])[0]);
        $byBarcode = $o->get('/admin/search?q=BK-BR&types=product')->json('results.product');
        $this->assertNotEmpty($byBarcode);
        $fac = $o->get('/admin/search?q=Restau&types=facility')->json('results.facility');
        $this->assertSame('Restaurant', $fac[0]['title']);
        $this->assertSame(['facility'], array_keys($o->get('/admin/search?q=Restau&types=facility')->json('results')));

        // a cashier: products (order.create), facility (order.view), orders, receipts; never staff
        $c = $this->api('cashier1')->get('/admin/search?q=an')->assertOk()->json('results');
        $this->assertArrayNotHasKey('staff', $c);
        $this->assertArrayHasKey('product', $c);
        // a kitchen user holds none of the searchable permissions except order/prep: no staff, no customers, no receipts
        $k = $this->api('kitchen1')->get('/admin/search?q=an')->assertOk()->json('results');
        $this->assertArrayNotHasKey('staff', $k);
        $this->assertArrayNotHasKey('customer', $k);
        $this->assertArrayNotHasKey('receipt', $k);
        // asking for a type you may not read is silently empty, not an error
        $this->assertSame([], (array) $this->api('kitchen1')->get('/admin/search?q=Amaka&types=staff')->assertOk()->json('results'));
        $o->get('/admin/search?q=a')->assertStatus(422);
        $o->get('/admin/search?q=an&types=bogus')->assertStatus(422);
        $this->getJson('/api/v1/admin/search?q=an')->assertStatus(401);
    }

    public function test_search_finds_orders_and_receipts_by_number(): void
    {
        $o = $this->owner();
        $staff = DB::table('staff')->first(['id']);
        $orderId = Ids::uuid7();
        DB::table('order')->insert(['id' => Ids::toBinary($orderId), 'organization_id' => Ids::toBinary(DemoIds::org()), 'site_id' => Ids::toBinary(DemoIds::site()), 'facility_unit_id' => Ids::toBinary(DemoIds::facility('CAFE')),
            'order_number' => 'ZZ-SEARCH-001', 'status' => 'SENT', 'created_by' => $staff->id, 'total' => '1500.0000']);
        $r = $o->get('/admin/search?q=ZZ-SEARCH&types=order')->assertOk()->json('results.order');
        $this->assertCount(1, $r);
        $this->assertSame($orderId, $r[0]['id']);
        $this->assertSame([], $o->get('/admin/search?q=ZZ-SEARCH-999&types=order')->json('results.order'));
    }

    public function test_audit_filters_history_panel_and_permission_scope(): void
    {
        $o = $this->owner();
        $fid = $o->post('/organization/facilities', ['code' => 'HIST', 'name' => 'History'])->json('id');
        $o->patch("/organization/facilities/{$fid}", ['name' => 'History 2'], ['If-Match' => '"1"'])->assertOk();
        $o->patch("/organization/facilities/{$fid}", ['description' => 'x'], ['If-Match' => '"2"'])->assertOk();

        $panel = $o->get("/audit?entityType=Facility&entityId={$fid}&order=desc")->assertOk()->json('items');
        $this->assertSame(['config.facility.update', 'config.facility.update', 'config.facility.create'], array_column($panel, 'action'));
        $this->assertSame('Ebiye Owei', $panel[0]['actorName']);
        $this->assertSame(['description' => 'x'], $panel[0]['newValue']);

        $this->assertCount(3, $o->get("/audit?facilityId={$fid}")->json('items'));
        $this->assertCount(2, $o->get("/audit?entityId={$fid}&actionPrefix=config.facility.up")->json('items'));
        $this->assertCount(1, $o->get("/audit?entityId={$fid}&action=config.facility.create")->json('items'));
        $future = urlencode(now('UTC')->addDay()->format('Y-m-d\TH:i:s\Z'));
        $this->assertCount(0, $o->get("/audit?entityId={$fid}&from={$future}")->json('items'));
        $this->assertCount(3, $o->get("/audit?entityId={$fid}&to={$future}")->json('items'));
        $this->assertCount(3, $o->get('/audit?entityTypes=Facility,Role&entityId='.$fid)->json('items'));
        $o->get('/audit?from=not-a-date')->assertStatus(422);
        $first = $o->get("/audit?entityId={$fid}&order=desc&limit=2")->json();
        $this->assertCount(2, $first['items']);
        $this->assertNotNull($first['nextCursor']);
        $next = $o->get("/audit?entityId={$fid}&order=desc&limit=2&cursor=".urlencode($first['nextCursor']))->json('items');
        $this->assertSame(['config.facility.create'], array_column($next, 'action'));

        // config.view (manager1) reads configuration history but not, e.g., login/payment audit; audit.view holders see everything
        $m = $this->api('manager1');
        $this->assertCount(3, $m->get("/audit?entityId={$fid}")->assertOk()->json('items'));
        $types = array_unique(array_column($m->get('/audit?limit=200')->json('items'), 'entityType'));
        foreach ($types as $t) {
            $this->assertContains($t, \App\Domain\Audit\Http\Controllers\AuditController::CONFIG_ENTITY_TYPES);
        }
        $this->api('cashier1')->get('/audit')->assertStatus(403);
    }
}
