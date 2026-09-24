<?php

namespace Tests\Feature\Config;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class DeviceConfigTest extends ConfigTestCase
{
    private function device(string $type): object
    {
        return DB::table('device')->where('device_type', $type)->where('is_revoked', 0)->first() ?? $this->fail("no demo {$type} device");
    }

    public function test_assign_home_facility_operating_point_mode_and_name(): void
    {
        $o = $this->owner();
        $d = $this->device('TABLET');
        $id = Ids::fromBinary($d->id);
        $fac = DemoIds::facility('RESTAURANT');
        $op = DemoIds::operatingPoint('RESTAURANT', 'MAIN_DINING');
        $ver = (int) $d->row_version;

        $o->patch("/devices/{$id}", ['name' => 'Waiter tablet 9'], [])->assertStatus(428);
        $r = $o->patch("/devices/{$id}", ['name' => 'Waiter tablet 9', 'facilityId' => $fac, 'operatingPointId' => $op, 'mode' => 'SUPERVISOR'], ['If-Match' => '"'.$ver.'"'])->assertOk();
        $this->assertSame('Waiter tablet 9', $r->json('name'));
        $this->assertSame($fac, $r->json('homeFacilityId'));
        $this->assertSame($op, $r->json('operatingPointId'));
        $this->assertSame('SUPERVISOR', $r->json('mode'));
        $r->assertHeader('ETag', '"'.($ver + 1).'"');

        $a = $this->audit('config.device.update', $id);
        $this->assertCount(1, $a);
        $this->assertSame('Waiter tablet 9', $a[0]->new['name']);
        $this->assertEmpty($this->outbox($id), 'devices are node-local hardware: audited, not synced');
        // stale writer
        $o->patch("/devices/{$id}", ['name' => 'Stale'], ['If-Match' => '"'.$ver.'"'])->assertStatus(412);
    }

    public function test_validation_and_guards(): void
    {
        $o = $this->owner();
        $d = $this->device('TABLET');
        $id = Ids::fromBinary($d->id);
        $v = fn () => '"'.DB::table('device')->where('id', $d->id)->value('row_version').'"';
        $o->patch("/devices/{$id}", ['mode' => 'KDS'], ['If-Match' => $v()])->assertStatus(422)->assertJsonValidationErrors(['mode']);
        $o->patch("/devices/{$id}", ['mode' => 'NOPE'], ['If-Match' => $v()])->assertStatus(422);
        $o->patch("/devices/{$id}", ['facilityId' => Ids::uuid7()], ['If-Match' => $v()])->assertStatus(422);
        // operating point of another facility
        $o->patch("/devices/{$id}", ['facilityId' => DemoIds::facility('RESTAURANT'), 'operatingPointId' => DemoIds::operatingPoint('POOL_BAR', 'POOL_DECK')], ['If-Match' => $v()])->assertStatus(422)->assertJsonValidationErrors(['operatingPointId']);
        // revoked devices cannot be edited
        DB::table('device')->where('id', $d->id)->update(['is_revoked' => 1]);
        $o->patch("/devices/{$id}", ['name' => 'x'], ['If-Match' => $v()])->assertStatus(409)->assertJsonPath('code', 'device_revoked');
    }

    public function test_kds_device_needs_a_station_and_permission_is_required(): void
    {
        $o = $this->owner();
        $d = $this->device('KDS');
        $id = Ids::fromBinary($d->id);
        $ver = '"'.$d->row_version.'"';
        $o->patch("/devices/{$id}", ['facilityId' => DemoIds::facility('RESTAURANT'), 'operatingPointId' => DemoIds::operatingPoint('RESTAURANT', 'MAIN_DINING')], ['If-Match' => $ver])->assertStatus(422);
        $ok = $o->patch("/devices/{$id}", ['facilityId' => DemoIds::facility('RESTAURANT'), 'operatingPointId' => DemoIds::operatingPoint('RESTAURANT', 'RESTAURANT_COUNTER')], ['If-Match' => $ver])->assertOk();
        $this->assertSame(DemoIds::operatingPoint('RESTAURANT', 'RESTAURANT_COUNTER'), $ok->json('operatingPointId'));

        $m = $this->managerLacking(['device.manage']);
        $m->patch("/devices/{$id}", ['name' => 'nope'], ['If-Match' => '"1"'])->assertStatus(403)->assertJsonPath('permission', 'device.manage');
        $this->api('manager1')->patch("/devices/{$id}", ['name' => 'nope'], ['If-Match' => '"1"'])->assertStatus(403); // MANAGER default bundle has no device.manage
        $it = $this->api('itadmin1');
        $it->patch("/devices/{$id}", ['name' => 'IT renamed'], ['If-Match' => '"'.DB::table('device')->where('id', $d->id)->value('row_version').'"'])->assertOk();
    }
}
