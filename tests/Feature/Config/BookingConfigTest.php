<?php

namespace Tests\Feature\Config;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class BookingConfigTest extends ConfigTestCase
{
    private function resource(): string
    {
        return DemoIds::resource('LAWN_TENNIS', 'COURT_1');
    }

    private function ver(string $id): string
    {
        return '"'.DB::table('bookable_resource')->where('id', Ids::toBinary($id))->value('row_version').'"';
    }

    public function test_weekly_schedule_replace_validation_and_versioning(): void
    {
        $o = $this->owner();
        $rid = $this->resource();
        $ok = $o->put("/bookings/resources/{$rid}/schedule", ['windows' => [
            ['dayOfWeek' => 1, 'open' => '08:00', 'close' => '12:00'], ['dayOfWeek' => 1, 'open' => '14:00', 'close' => '22:00'], ['dayOfWeek' => 6, 'open' => '07:00', 'close' => '24:00'],
        ]], ['If-Match' => $this->ver($rid)])->assertOk();
        $this->assertCount(3, $ok->json('windows'));
        $this->assertSame('08:00', $ok->json('windows.0.open'));
        $get = $o->get("/bookings/resources/{$rid}/schedule")->assertOk()->assertHeader('ETag');
        $this->assertCount(3, $get->json('windows'));
        // overlap + bad times
        $o->put("/bookings/resources/{$rid}/schedule", ['windows' => [['dayOfWeek' => 2, 'open' => '08:00', 'close' => '12:00'], ['dayOfWeek' => 2, 'open' => '11:00', 'close' => '13:00']]], ['If-Match' => $this->ver($rid)])->assertStatus(422)->assertJsonValidationErrors(['windows.1']);
        $o->put("/bookings/resources/{$rid}/schedule", ['windows' => [['dayOfWeek' => 2, 'open' => '12:00', 'close' => '08:00']]], ['If-Match' => $this->ver($rid)])->assertStatus(422);
        $o->put("/bookings/resources/{$rid}/schedule", ['windows' => [['dayOfWeek' => 9, 'open' => '08:00', 'close' => '09:00']]], ['If-Match' => $this->ver($rid)])->assertStatus(422);
        $o->put("/bookings/resources/{$rid}/schedule", ['windows' => []], [])->assertStatus(428);
        $o->put("/bookings/resources/{$rid}/schedule", ['windows' => []], ['If-Match' => '"1"'])->assertStatus(412);
        $this->assertNotEmpty($this->audit('config.booking.schedule.set', $rid));
        $this->assertNotEmpty($this->outbox($rid, 'resourceSchedule'));
        // empty = property default hours again
        $o->put("/bookings/resources/{$rid}/schedule", ['windows' => []], ['If-Match' => $this->ver($rid)])->assertOk()->assertJsonPath('windows', []);
    }

    public function test_blackouts_list_create_and_delete(): void
    {
        $o = $this->owner();
        $rid = $this->resource();
        $fac = DemoIds::facility('LAWN_TENNIS');
        $start = now('UTC')->addDays(3)->format('Y-m-d\TH:i:s\Z');
        $end = now('UTC')->addDays(4)->format('Y-m-d\TH:i:s\Z');
        $res = $o->post("/bookings/resources/{$rid}/blackouts", ['start' => $start, 'end' => $end, 'reason' => 'Resurfacing'])->assertStatus(201);
        $facWide = $o->post('/bookings/blackouts', ['facilityId' => $fac, 'start' => $start, 'end' => $end, 'reason' => 'Tournament'])->assertStatus(201);
        $list = $o->get("/bookings/resources/{$rid}/blackouts")->assertOk()->json('items');
        $this->assertCount(2, $list);
        $this->assertCount(0, $o->get("/bookings/resources/{$rid}/blackouts?from=".urlencode(now('UTC')->addDays(10)->format('Y-m-d\TH:i:s\Z')))->json('items'));
        $o->delete('/bookings/blackouts/'.$res->json('id'), idem: false)->assertNoContent();
        $o->delete('/bookings/blackouts/'.$facWide->json('id'), idem: false)->assertNoContent();
        $o->delete('/bookings/blackouts/'.$facWide->json('id'), idem: false)->assertStatus(404);
        $this->assertCount(0, $o->get("/bookings/resources/{$rid}/blackouts")->json('items'));
        $this->assertNotEmpty($this->audit('config.booking.blackout.delete'));
        $this->assertSame([1, 2], array_column($this->outbox($res->json('id'), 'blackout'), 'version'));
        $o->post('/bookings/blackouts', ['facilityId' => $fac, 'start' => $end, 'end' => $start])->assertStatus(422);
    }

    public function test_resource_rules_override_and_inherit(): void
    {
        $o = $this->owner();
        $rid = $this->resource();
        $before = $o->get("/bookings/resources/{$rid}/rules")->assertOk();
        $this->assertNull($before->json('holdTtlSeconds'));
        $put = $o->put("/bookings/resources/{$rid}/rules", ['holdTtlSeconds' => 120, 'cancelCutoffMinutes' => 30, 'cancelFeePercent' => 15.5], ['If-Match' => $this->ver($rid)])->assertOk();
        $this->assertSame(120, $put->json('holdTtlSeconds'));
        $this->assertSame(120, $put->json('effective.holdTtlSeconds'));
        $this->assertSame(15.5, $put->json('effective.cancelFeePercent'));
        $this->assertNull($put->json('maxReschedules'));
        $this->assertNotNull($put->json('effective.maxReschedules'), 'inherited from the property default');
        $o->put("/bookings/resources/{$rid}/rules", ['holdTtlSeconds' => 5], ['If-Match' => $this->ver($rid)])->assertStatus(422);
        $o->put("/bookings/resources/{$rid}/rules", ['cancelFeePercent' => 101], ['If-Match' => $this->ver($rid)])->assertStatus(422);
        $o->put("/bookings/resources/{$rid}/rules", [], ['If-Match' => '"1"'])->assertStatus(412);
        $o->put("/bookings/resources/{$rid}/rules", [], ['If-Match' => $this->ver($rid)])->assertOk();
        $this->assertNull($o->get("/bookings/resources/{$rid}/rules")->json('holdTtlSeconds'));
        $this->assertSame(0, DB::table('booking_rule')->where('resource_id', Ids::toBinary($rid))->count());
        $this->assertCount(2, $this->audit('config.booking.rules.set', $rid));
    }

    public function test_permission_is_booking_configure(): void
    {
        $rid = $this->resource();
        $this->api('cashier1')->get("/bookings/resources/{$rid}/schedule")->assertStatus(403)->assertJsonPath('permission', 'booking.configure');
        $m = $this->managerLacking(['booking.configure']);
        $m->put("/bookings/resources/{$rid}/rules", [], ['If-Match' => '"1"'])->assertStatus(403);
        $m->post('/bookings/blackouts', ['facilityId' => DemoIds::facility('LAWN_TENNIS'), 'start' => '2030-01-01T00:00:00Z', 'end' => '2030-01-02T00:00:00Z'])->assertStatus(403);
    }
}
