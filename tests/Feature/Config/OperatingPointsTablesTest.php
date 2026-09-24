<?php

namespace Tests\Feature\Config;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class OperatingPointsTablesTest extends ConfigTestCase
{
    private function newRestaurant(): string
    {
        return $this->owner()->post('/organization/facilities', ['code' => 'OPS_'.bin2hex(random_bytes(2)), 'name' => 'Ops test', 'templateKey' => 'RESTAURANT'])->assertStatus(201)->json('id');
    }

    public function test_operating_point_crud_with_kds_station_and_audit(): void
    {
        $o = $this->owner();
        $fid = $o->post('/organization/facilities', ['code' => 'OPSX', 'name' => 'Ops X'])->json('id');
        $p = $o->post("/organization/facilities/{$fid}/operating-points", ['code' => 'bar_pass', 'name' => 'Bar pass', 'kind' => 'station', 'kdsStation' => ['kind' => 'BAR']])->assertStatus(201);
        $this->assertSame('BAR_PASS', $p->json('code'));
        $this->assertSame('BAR', $p->json('kdsStation.kind'));
        $this->assertNotNull($p->json('kdsStation.prepRouteId'), 'prep route defaulted');
        $id = $p->json('id');
        $this->assertNotNull(DB::table('kds_station')->where('id', Ids::toBinary($id))->first());

        $o->post("/organization/facilities/{$fid}/operating-points", ['code' => 'BAR_PASS', 'name' => 'Dup', 'kind' => 'COUNTER'])->assertStatus(409)->assertJsonPath('code', 'operating_point_code_taken');
        $o->post("/organization/facilities/{$fid}/operating-points", ['code' => 'C1', 'name' => 'C', 'kind' => 'COUNTER', 'kdsStation' => ['kind' => 'BAR']])->assertStatus(422);
        $o->post("/organization/facilities/{$fid}/operating-points", ['code' => 'C2', 'name' => 'C', 'kind' => 'WRONG'])->assertStatus(422);

        $o->patch("/organization/operating-points/{$id}", ['name' => 'Renamed'], [])->assertStatus(428);
        $u = $o->patch("/organization/operating-points/{$id}", ['name' => 'Renamed'], ['If-Match' => '"1"'])->assertOk();
        $this->assertSame('Renamed', $u->json('name'));
        $this->assertSame('Renamed', DB::table('kds_station')->where('id', Ids::toBinary($id))->value('name'));
        $o->patch("/organization/operating-points/{$id}", ['name' => 'Stale'], ['If-Match' => '"1"'])->assertStatus(412);

        $list = $o->get("/organization/operating-points?facilityId={$fid}")->assertOk()->json('items');
        $this->assertCount(1, $list);
        $o->post("/organization/operating-points/{$id}/deactivate", [], ['If-Match' => '"2"'], idem: false)->assertOk()->assertJsonPath('active', false);
        $this->assertSame(0, (int) DB::table('kds_station')->where('id', Ids::toBinary($id))->value('is_active'));
        $this->assertCount(0, $o->get("/organization/operating-points?facilityId={$fid}")->json('items'));
        $this->assertCount(1, $o->get("/organization/operating-points?facilityId={$fid}&includeInactive=true")->json('items'));
        $this->assertNotEmpty($this->audit('config.operating_point.create', $id));
        $this->assertNotEmpty($this->outbox($id, 'operatingPoint'));
    }

    public function test_operating_point_in_use_by_device_cannot_be_deactivated(): void
    {
        $o = $this->owner();
        $fid = DemoIds::facility('RESTAURANT');
        $op = DemoIds::operatingPoint('RESTAURANT', 'MAIN_DINING');
        $dev = DB::table('device')->where('is_revoked', 0)->first(['id']);
        DB::table('device')->where('id', $dev->id)->update(['operating_point_id' => Ids::toBinary($op)]);
        $r = $o->post("/organization/operating-points/{$op}/deactivate", [], ['If-Match' => '"1"'], idem: false)->assertStatus(409);
        $this->assertSame('operating_point_in_use', $r->json('code'));
        $this->assertSame('assigned_devices', $r->json('blockers.0.type'));
        $this->assertNotNull($fid);
    }

    public function test_tables_bulk_create_rename_merge_and_guards(): void
    {
        $o = $this->owner();
        $fid = $this->newRestaurant();
        $area = collect($o->get("/organization/operating-points?facilityId={$fid}")->json('items'))->firstWhere('kind', 'TABLE_AREA')['id'];

        $b = $o->post("/organization/facilities/{$fid}/tables/bulk", ['prefix' => 'T', 'from' => 1, 'to' => 20, 'seats' => 4, 'operatingPointId' => $area])->assertStatus(201);
        $this->assertCount(20, $b->json('created'));
        $this->assertSame('T1', $b->json('created.0.label'));
        $this->assertSame('T20', $b->json('created.19.label'));
        $again = $o->post("/organization/facilities/{$fid}/tables/bulk", ['prefix' => 'T', 'from' => 18, 'to' => 22, 'seats' => 2])->assertStatus(201);
        $this->assertCount(2, $again->json('created'));
        $this->assertSame(['T18', 'T19', 'T20'], $again->json('skipped'));
        $o->post("/organization/facilities/{$fid}/tables/bulk", ['prefix' => 'P', 'from' => 1, 'to' => 3, 'padWidth' => 2])->assertStatus(201)->assertJsonPath('created.2.label', 'P03');
        $o->post("/organization/facilities/{$fid}/tables/bulk", ['prefix' => 'X', 'from' => 1, 'to' => 600])->assertStatus(422);

        $list = $o->get("/organization/tables?facilityId={$fid}&limit=200")->assertOk()->json('items');
        $this->assertCount(25, $list);
        $t1 = collect($list)->firstWhere('label', 'T1');
        $t2 = collect($list)->firstWhere('label', 'T2');

        $o->patch("/organization/tables/{$t1['id']}", ['label' => 'T2'], ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('code', 'table_label_taken');
        $ren = $o->patch("/organization/tables/{$t1['id']}", ['label' => 'Window 1', 'seats' => 6], ['If-Match' => '"1"'])->assertOk();
        $this->assertSame(6, $ren->json('seats'));
        $o->patch("/organization/tables/{$t1['id']}", ['seats' => 8], ['If-Match' => '"1"'])->assertStatus(412);

        // merge Window 1 (6 seats) into T2 (4 seats)
        $m = $o->post("/organization/tables/{$t1['id']}/merge", ['intoTableId' => $t2['id']], ['If-Match' => '"2"'], idem: false)->assertOk();
        $this->assertSame(10, $m->json('effectiveSeats'));
        $this->assertSame([$t1['id']], $m->json('mergedTableIds'));
        $o->post("/organization/tables/{$t1['id']}/merge", ['intoTableId' => $t2['id']], ['If-Match' => '"3"'], idem: false)->assertStatus(409)->assertJsonPath('code', 'table_already_merged');
        // the order-taker table list hides merged-away tables
        $names = collect($o->get("/tables?facilityId={$fid}&limit=200")->json('items'))->pluck('label');
        $this->assertNotContains('Window 1', $names);
        $o->post("/organization/tables/{$t1['id']}/unmerge", [], ['If-Match' => '"3"'], idem: false)->assertOk()->assertJsonPath('mergedIntoId', null);

        // occupied / open-order tables cannot be deactivated
        DB::table('dining_table')->where('id', Ids::toBinary($t2['id']))->update(['status' => 'OCCUPIED']);
        $cur = DB::table('dining_table')->where('id', Ids::toBinary($t2['id']))->value('row_version');
        $o->post("/organization/tables/{$t2['id']}/deactivate", [], ['If-Match' => '"'.$cur.'"'], idem: false)->assertStatus(409)->assertJsonPath('code', 'table_in_use');
        DB::table('dining_table')->where('id', Ids::toBinary($t2['id']))->update(['status' => 'FREE']);
        $o->post("/organization/tables/{$t2['id']}/deactivate", [], ['If-Match' => '"'.$cur.'"'], idem: false)->assertOk()->assertJsonPath('active', false);
        $this->assertNotEmpty($this->audit('config.tables.bulk_create', $fid));
    }

    public function test_tables_need_table_service_capability_and_facility_manage(): void
    {
        $o = $this->owner();
        $store = DemoIds::facility('MAIN_STORE'); // INVENTORY only
        $o->post("/organization/facilities/{$store}/tables", ['label' => 'T1'])->assertStatus(422)->assertJsonPath('code', 'capability_disabled');
        $m = $this->managerLacking(['facility.manage']);
        $m->post('/organization/facilities/'.DemoIds::facility('RESTAURANT').'/tables', ['label' => 'ZZ'])->assertStatus(403);
        $m->post('/organization/facilities/'.DemoIds::facility('RESTAURANT').'/tables/bulk', ['prefix' => 'Z', 'from' => 1, 'to' => 2])->assertStatus(403);
    }
}
