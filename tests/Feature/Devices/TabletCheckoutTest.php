<?php

namespace Tests\Feature\Devices;

use App\Domain\Identity\Models\Staff;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\DemoApi;
use Tests\TestCase;

class TabletCheckoutTest extends TestCase
{
    use DemoApi;

    private string $dev;

    private string $devToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->dev = DemoIds::device('TABLET_WAITER_01');
        $this->devToken = $this->deviceToken('TABLET_WAITER_01');
    }

    public function test_checkout_and_checkin_lifecycle_with_audit(): void
    {
        $w = $this->api('wait1', $this->devToken);
        $staff = $w->auth['staff']['id'];
        $shift = Ids::uuid7();
        $key = ['Idempotency-Key' => 'checkout-key-0000001'];

        $out = $w->post("/devices/{$this->dev}/checkout", ['staffId' => $staff, 'facilityId' => $this->facilityId('RESTAURANT'), 'shiftId' => $shift, 'openingFloat' => '5000'], $key, false)->assertOk();
        $this->assertSame($this->facilityId('RESTAURANT'), $out->json('facilityId'));
        $this->assertSame($staff, $out->json('checkout.staffId'));
        $this->assertSame($shift, $out->json('checkout.shiftId'));
        $this->assertSame('5000.0000', $out->json('checkout.openingFloat'));
        $this->assertNull($out->json('checkout.checkedInAt'));
        $this->assertNotNull($out->json('checkout.checkedOutAt'));

        // idempotent replay + reflected on GET
        $w->post("/devices/{$this->dev}/checkout", ['staffId' => $staff, 'facilityId' => $this->facilityId('RESTAURANT'), 'shiftId' => $shift, 'openingFloat' => '5000'], $key, false)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->api('itadmin1')->get("/devices/{$this->dev}")->assertJsonPath('checkout.staffId', $staff)->assertJsonPath('facilityId', $this->facilityId('RESTAURANT'));
        $this->assertSame(1, DB::table('tablet_checkout')->where('device_id', Ids::toBinary($this->dev))->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'device.checkout')->count());

        // a second checkout (different staff/facility, fresh key) is refused; the same request with a fresh key converges
        $w2 = $this->api('wait2');
        $w2->post("/devices/{$this->dev}/checkout", ['staffId' => $w2->auth['staff']['id'], 'facilityId' => $this->facilityId('POOL_BAR')])->assertStatus(409)->assertJsonPath('code', 'concurrency_conflict');
        $w->post("/devices/{$this->dev}/checkout", ['staffId' => $staff, 'facilityId' => $this->facilityId('RESTAURANT')])->assertOk();
        $this->assertSame(1, DB::table('tablet_checkout')->where('device_id', Ids::toBinary($this->dev))->count());

        // another (non-admin) staff member cannot return it; the owner can (FORCED); the holder normally can
        $this->api('wait2')->post("/devices/{$this->dev}/checkin", [])->assertStatus(403);
        $in = $w->post("/devices/{$this->dev}/checkin", ['closingNote' => 'all good', 'cashSessionId' => Ids::uuid7()])->assertOk();
        $this->assertNull($in->json('facilityId'));
        $this->assertNotNull($in->json('checkout.checkedInAt'));
        $this->assertSame('RETURNED', $in->json('checkout.status'));
        $this->assertNull($this->api('itadmin1')->get("/devices/{$this->dev}")->json('checkout'));
        $w->post("/devices/{$this->dev}/checkin", [])->assertStatus(409); // nothing to return
        $this->assertSame(1, DB::table('audit_log')->where('action', 'device.checkin')->count());

        // history is kept and the device can be checked out again to someone else
        $w2->post("/devices/{$this->dev}/checkout", ['staffId' => $w2->auth['staff']['id'], 'facilityId' => $this->facilityId('POOL_BAR')])->assertOk();
        $this->assertSame(2, DB::table('tablet_checkout')->where('device_id', Ids::toBinary($this->dev))->count());
        $this->api('owner1')->post("/devices/{$this->dev}/checkin", [])->assertOk()->assertJsonPath('checkout.status', 'FORCED');
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_rules_facility_staff_and_device_state(): void
    {
        $w = $this->api('wait1');
        $me = $w->auth['staff']['id'];
        // facility the waiter is not assigned to
        $w->post("/devices/{$this->dev}/checkout", ['staffId' => $me, 'facilityId' => $this->facilityId('POOL_BAR')])->assertStatus(403)->assertJsonPath('code', 'facility_mismatch');
        // checking out to someone else needs staff.manage / device.register
        $w->post("/devices/{$this->dev}/checkout", ['staffId' => DemoIds::staff('wait2'), 'facilityId' => $this->facilityId('POOL_BAR')])->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->api('manager1')->post("/devices/{$this->dev}/checkout", ['staffId' => DemoIds::staff('wait2'), 'facilityId' => $this->facilityId('POOL_BAR')])->assertOk();
        // validation
        $this->api('manager1')->post('/devices/'.DemoIds::device('TABLET_WAITER_02').'/checkout', ['staffId' => 'nope'])->assertStatus(422)->assertJsonStructure(['errors' => ['staffId', 'facilityId']]);
        $this->api('manager1')->post('/devices/'.DemoIds::device('TABLET_WAITER_02').'/checkout', ['staffId' => Ids::uuid7(), 'facilityId' => $this->facilityId('CAFE')])->assertStatus(422)->assertJsonPath('errors.staffId.0', 'Unknown staff member.');
        $this->api('manager1')->post('/devices/'.Ids::uuid7().'/checkout', ['staffId' => $me, 'facilityId' => $this->facilityId('CAFE')])->assertStatus(404);
        $this->postJson("/api/v1/devices/{$this->dev}/checkout", [])->assertStatus(401);
        $w->post("/devices/{$this->dev}/checkout", ['staffId' => $me, 'facilityId' => $this->facilityId('RESTAURANT')], ['Idempotency-Key' => ''], false)->assertStatus(400)->assertJsonPath('code', 'idempotency_key_missing');

        // suspended staff cannot be handed a tablet
        Staff::query()->find(DemoIds::staff('wait2'))->update(['is_active' => 0]);
        $this->api('owner1')->post('/devices/'.DemoIds::device('TABLET_WAITER_02').'/checkout', ['staffId' => DemoIds::staff('wait2'), 'facilityId' => $this->facilityId('POOL_BAR')])->assertStatus(403);
    }

    public function test_database_enforces_one_active_checkout_per_device(): void
    {
        $this->api('manager1')->post("/devices/{$this->dev}/checkout", ['staffId' => DemoIds::staff('wait1'), 'facilityId' => $this->facilityId('RESTAURANT')])->assertOk();
        $row = (array) DB::table('tablet_checkout')->first();
        DB::beginTransaction();
        try {
            $dup = ['id' => Ids::toBinary(Ids::uuid7())] + collect($row)->except(['id', 'active_device_id', 'checked_in_at'])->all();
            DB::table('tablet_checkout')->insert($dup);
            $this->fail('UNIQUE(active_device_id) must reject a second open checkout');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
        } finally {
            DB::rollBack();
        }
    }
}
