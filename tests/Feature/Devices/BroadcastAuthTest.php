<?php

namespace Tests\Feature\Devices;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use App\Support\Realtime\RealtimeEvent;
use Illuminate\Testing\TestResponse;
use Tests\Support\DemoApi;
use Tests\TestCase;

/** POST /broadcasting/auth — Pusher private-channel auth for Reverb with the contract's per-channel rules (api/realtime.md §2). */
class BroadcastAuthTest extends TestCase
{
    use DemoApi;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret']);
        $this->seedDemo();
    }

    private function auth(array $session, ?string $deviceToken, string $channel, bool $form = false): TestResponse
    {
        $headers = array_filter(['Authorization' => 'Bearer '.$session['accessToken'], 'X-Device-Token' => $deviceToken]);
        $body = ['socket_id' => '1234.5678', 'channel_name' => $channel];

        return $form ? $this->withHeaders($headers)->post('/api/v1/broadcasting/auth', $body, ['Accept' => 'application/json']) : $this->withHeaders($headers)->postJson('/api/v1/broadcasting/auth', $body);
    }

    private function signed(string $channel): string
    {
        return 'test-key:'.hash_hmac('sha256', "1234.5678:{$channel}", 'test-secret');
    }

    public function test_device_channel_only_for_the_device_itself(): void
    {
        $tok = $this->deviceToken('TABLET_WAITER_01');
        $s = $this->loginAs('wait1', $tok);
        $mine = 'private-device.'.DemoIds::device('TABLET_WAITER_01');
        $this->auth($s, $tok, $mine)->assertOk()->assertExactJson(['auth' => $this->signed($mine)]);
        $this->auth($s, $tok, $mine, form: true)->assertOk()->assertJsonPath('auth', $this->signed($mine)); // stock Pusher clients send form-encoded
        $this->auth($s, $tok, 'private-device.'.DemoIds::device('TABLET_WAITER_02'))->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->auth($s, null, $mine)->assertStatus(403); // no device token presented
    }

    public function test_kds_station_requires_permission_and_device_at_the_facility(): void
    {
        $station = 'private-kds.station.'.DemoIds::operatingPoint('MAIN_KITCHEN', 'MAIN_KITCHEN');
        $kdsTok = $this->deviceToken('KDS_MAIN_KITCHEN');
        $kitchen = $this->loginAs('kitchen1', $kdsTok);
        $this->auth($kitchen, $kdsTok, $station)->assertOk()->assertJsonPath('auth', $this->signed($station));

        // a waiter lacks prep_ticket.view
        $wTok = $this->deviceToken('TABLET_WAITER_01');
        $this->auth($this->loginAs('wait1', $wTok), $wTok, $station)->assertStatus(403);
        // right permission but the device is a tablet that is not checked out at the kitchen
        $kTablet = $this->deviceToken('TABLET_WAITER_02');
        $this->auth($this->loginAs('kitchen1', $kTablet), $kTablet, $station)->assertStatus(403);
        // ...until it is checked out at that facility
        $this->api('manager1')->post('/devices/'.DemoIds::device('TABLET_WAITER_02').'/checkout', ['staffId' => DemoIds::staff('kitchen1'), 'facilityId' => DemoIds::facility('MAIN_KITCHEN')])->assertOk();
        $this->auth($this->loginAs('kitchen1', $kTablet), $kTablet, $station)->assertOk();
        // unknown station
        $this->auth($kitchen, $kdsTok, 'private-kds.station.'.Ids::uuid7())->assertStatus(403);
    }

    public function test_facility_orders_channel_needs_order_permission_and_device_at_facility(): void
    {
        $ch = 'private-facility.'.DemoIds::facility('RESTAURANT').'.orders';
        $tok = $this->deviceToken('POS_RESTAURANT'); // fixed POS homed at the restaurant
        $this->auth($this->loginAs('cashier2', $tok), $tok, $ch)->assertOk();
        $this->auth($this->loginAs('cashier1', $tok), $tok, $ch)->assertStatus(403); // cashier1 works Reception, not the Restaurant
        $other = $this->deviceToken('POS_CAFE');
        $this->auth($this->loginAs('cashier2', $other), $other, $ch)->assertStatus(403); // device is at the Cafe
        $this->auth($this->loginAs('kitchen1', $tok), $tok, $ch)->assertStatus(403); // no order.create/order.view
    }

    public function test_site_status_and_protocol_errors(): void
    {
        $ch = 'private-site.status';
        $ktok = $this->deviceToken('KDS_POOL_BAR');
        $this->auth($this->loginAs('bartender1', $ktok), $ktok, $ch)->assertOk(); // KDS screens may show the health banner
        $tab = $this->deviceToken('TABLET_WAITER_01');
        $this->auth($this->loginAs('wait1', $tab), $tab, $ch)->assertStatus(403);
        $itTok = $this->deviceToken('POS_RECEPTION_1');
        $this->auth($this->loginAs('itadmin1', $itTok), null, $ch)->assertOk(); // config.manage holder, no device needed

        $s = $this->loginAs('owner1');
        $this->auth($s, null, 'presence-site.status')->assertStatus(422);
        $this->auth($s, null, 'private-unknown.channel')->assertStatus(403);
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $ch])->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer '.$s['accessToken']])->postJson('/api/v1/broadcasting/auth', [])->assertStatus(422);
    }

    public function test_realtime_events_use_the_contract_envelope_on_private_channels(): void
    {
        $e = new RealtimeEvent('device.abc', 'device.command', ['command' => 'LOCK', 'payload' => (object) []], 'evt-1', '2026-09-23T10:00:00.000000Z', 'corr-1');
        $this->assertSame('private-device.abc', $e->broadcastOn()[0]->name);
        $this->assertSame('device.command', $e->broadcastAs());
        $this->assertEquals(['eventId' => 'evt-1', 'occurredAt' => '2026-09-23T10:00:00.000000Z', 'correlationId' => 'corr-1', 'data' => ['command' => 'LOCK', 'payload' => (object) []]], $e->broadcastWith());
        $this->assertTrue($e->afterCommit, 'broadcast only after the DB transaction commits');
    }
}
