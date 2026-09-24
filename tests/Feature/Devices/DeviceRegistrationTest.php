<?php

namespace Tests\Feature\Devices;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\DeviceCommandService;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use App\Support\Realtime\RealtimeEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\DemoApi;
use Tests\TestCase;

class DeviceRegistrationTest extends TestCase
{
    use DemoApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function register(string $code, array $over = []): TestResponse
    {
        return $this->postJson('/api/v1/devices/register', $over + [
            'name' => 'Waiter Tablet 99', 'kind' => 'MOBILE_TABLET', 'hardwareId' => 'hw-99', 'platform' => 'android', 'appVersion' => '0.2.0', 'registrationCode' => $code,
        ]);
    }

    public function test_registration_code_flow_issues_a_hashed_device_token_once(): void
    {
        $it = $this->api('itadmin1');
        $this->api('cashier1')->post('/devices/registration-codes', [], [], false)->assertStatus(403)->assertJsonPath('permission', 'device.register');
        $code = $it->post('/devices/registration-codes', ['facilityId' => $this->facilityId('RECEPTION')], [], false)->assertStatus(201)->json();
        $this->assertMatchesRegularExpression('/^R7-[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code['code']);

        $r = $this->register($code['code'])->assertStatus(201);
        $token = $r->json('deviceToken');
        $this->assertStringStartsWith('r7d_', $token);
        $this->assertSame('MOBILE_TABLET', $r->json('device.kind'));
        $this->assertSame('ACTIVE', $r->json('device.status'));
        $this->assertSame($this->facilityId('RECEPTION'), $r->json('device.homeFacilityId'));
        $this->assertSame('ATTENDANT', $r->json('device.mode'));
        $this->assertSame(['id' => $this->facilityId('RECEPTION'), 'code' => 'RECEPTION'], array_intersect_key($r->json('device.homeFacility'), array_flip(['id', 'code'])));
        $this->assertNotEmpty($r->json('device.homeFacility.name'));
        $this->assertNotEmpty($r->json('device.homeFacility.kind'));
        $this->assertNull($r->json('device.checkout'));

        // only the SHA-256 is stored; the plaintext token and the code are nowhere in the DB
        $reg = DB::table('device_registration')->where('device_id', Ids::toBinary($r->json('device.id')))->first();
        $this->assertSame(hash('sha256', $token), $reg->token_hash);
        $dump = json_encode([DB::table('device_registration')->get(), DB::table('device_registration_code')->get(), DB::table('audit_log')->get(), DB::table('idempotency_record')->get()]);
        $this->assertStringNotContainsString($token, $dump);
        $this->assertStringNotContainsString(substr($code['code'], 3, 5), $dump);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'device.register')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'DeviceRegistered')->count());

        // single use
        $this->register($code['code'], ['hardwareId' => 'hw-100'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->register('R7-AAAAA-BBBBB')->assertStatus(422);

        // the token authenticates the device
        $this->withHeaders(['X-Device-Token' => $token])->getJson('/api/v1/devices/'.$r->json('device.id'))->assertOk()->assertJsonPath('name', 'Waiter Tablet 99');
    }

    public function test_expired_code_and_validation(): void
    {
        $it = $this->api('itadmin1');
        $code = $it->post('/devices/registration-codes', [], [], false)->json('code');
        DB::table('device_registration_code')->update(['expires_at' => now('UTC')->subMinute()]);
        $this->register($code)->assertStatus(422);
        $this->postJson('/api/v1/devices/register', ['name' => 'x'])->assertStatus(422)->assertJsonStructure(['errors' => ['kind', 'hardwareId', 'registrationCode']]);
        $this->register('whatever', ['kind' => 'TOASTER'])->assertStatus(422)->assertJsonStructure(['errors' => ['kind']]);
    }

    public function test_reregistering_the_same_hardware_rotates_the_token_and_revoked_devices_cannot_return(): void
    {
        $it = $this->api('itadmin1');
        $c1 = $it->post('/devices/registration-codes', [], [], false)->json('code');
        $c2 = $it->post('/devices/registration-codes', [], [], false)->json('code');
        $first = $this->register($c1)->assertStatus(201);
        $second = $this->register($c2, ['name' => 'Renamed'])->assertStatus(201);

        $this->assertSame($first->json('device.id'), $second->json('device.id'), 'same hardware id = same device row');
        $this->assertSame('Renamed', $second->json('device.name'));
        $this->assertSame(1, DB::table('device')->where('hardware_id', 'hw-99')->count());
        $this->withHeaders(['X-Device-Token' => $first->json('deviceToken')])->getJson('/api/v1/devices/'.$first->json('device.id'))->assertStatus(403)->assertJsonPath('code', 'device_not_registered');
        $this->withHeaders(['X-Device-Token' => $second->json('deviceToken')])->getJson('/api/v1/devices/'.$first->json('device.id'))->assertOk();

        $it->post('/devices/'.$first->json('device.id').'/revoke')->assertOk();
        $c3 = $it->post('/devices/registration-codes', [], [], false)->json('code');
        $this->register($c3)->assertStatus(403)->assertJsonPath('code', 'device_revoked');
    }

    public function test_device_middleware_and_login_binds_the_session_to_the_device(): void
    {
        $token = $this->deviceToken('POS_RECEPTION_1');
        $auth = $this->loginAs('cashier1', $token);
        $this->assertSame(DemoIds::device('POS_RECEPTION_1'), $auth['session']['deviceId']);
        $this->assertSame(DemoIds::device('POS_RECEPTION_1'), DB::table('session')->where('id', Ids::toBinary($auth['session']['id']))->value('device_id') ? DemoIds::device('POS_RECEPTION_1') : null);

        // combined staff+device requirement
        Route::middleware(['api', 'auth:staff', 'staff.device'])->get('api/v1/_test/staff-and-device', fn () => ['ok' => true]);
        $get = fn (?string $dev) => $this->withHeaders(array_filter(['Authorization' => 'Bearer '.$auth['accessToken'], 'X-Device-Token' => $dev]))->getJson('/api/v1/_test/staff-and-device');
        $get($token)->assertOk();
        $get(null)->assertStatus(403)->assertJsonPath('code', 'device_not_registered');
        $get('r7d_bogus')->assertStatus(403)->assertJsonPath('code', 'device_not_registered');
        $get($this->deviceToken('POS_RECEPTION_2'))->assertStatus(403)->assertJsonPath('code', 'device_not_registered'); // session belongs to another device
        $this->withHeaders(['X-Device-Token' => $token])->getJson('/api/v1/_test/staff-and-device')->assertStatus(401);
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'cashier1', 'secret' => '1234'], ['X-Device-Token' => 'r7d_bogus'])->assertStatus(403);

        // /auth/me reports the device
        $this->withHeaders(['Authorization' => 'Bearer '.$auth['accessToken'], 'X-Device-Token' => $token])->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('device.id', DemoIds::device('POS_RECEPTION_1'))->assertJsonPath('session.deviceId', DemoIds::device('POS_RECEPTION_1'));
    }

    public function test_list_get_filters_and_authorization(): void
    {
        $it = $this->api('itadmin1');
        $page = $it->get('/devices?limit=10')->assertOk();
        $this->assertCount(10, $page->json('items'));
        $this->assertNotNull($page->json('nextCursor'));
        $seen = array_column($page->json('items'), 'id');
        $next = $it->get('/devices?limit=100&cursor='.$page->json('nextCursor'))->json('items');
        $this->assertEmpty(array_intersect($seen, array_column($next, 'id')));
        $this->assertCount(33, array_merge($seen, array_column($next, 'id')));

        $this->assertCount(2, $it->get('/devices?filter[facilityId]='.$this->facilityId('SUPERMARKET').'&limit=100')->json('items'));
        $this->assertCount(0, $it->get('/devices?filter[status]=REVOKED')->json('items'));

        $this->api('cashier1')->get('/devices')->assertStatus(403);
        $dev = DemoIds::device('POS_RESTAURANT');
        $this->api('cashier1')->get("/devices/{$dev}")->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $it->get("/devices/{$dev}")->assertOk()->assertJsonPath('kind', 'POS_TERMINAL')->assertHeader('ETag');
        $this->withHeaders(['X-Device-Token' => $this->deviceToken('POS_RESTAURANT')])->getJson("/api/v1/devices/{$dev}")->assertOk(); // a device may read itself
        $this->withHeaders(['X-Device-Token' => $this->deviceToken('POS_CAFE')])->getJson("/api/v1/devices/{$dev}")->assertStatus(403); // ...but not another
        $this->getJson("/api/v1/devices/{$dev}")->assertStatus(401);
        $it->get('/devices/'.Ids::uuid7())->assertStatus(404);
    }

    public function test_status_heartbeat_returns_pending_commands_and_min_client_version(): void
    {
        $dev = DemoIds::device('TABLET_WAITER_01');
        $tok = ['X-Device-Token' => $this->deviceToken('TABLET_WAITER_01')];
        $ack = $this->withHeaders($tok)->postJson("/api/v1/devices/{$dev}/status", ['appVersion' => '0.3.1', 'batteryPercent' => 64, 'networkType' => 'WIFI', 'queuedMutations' => 2, 'printerStatus' => 'OK'])->assertOk();
        $this->assertSame([], $ack->json('commands'));
        $this->assertSame('0.1.0', $ack->json('minClientVersion'));
        $this->assertNotNull($ack->json('serverTime'));
        $row = DB::table('device')->where('id', Ids::toBinary($dev))->first();
        $this->assertSame('0.3.1', $row->app_version);
        $this->assertSame(64, json_decode($row->last_status)->batteryPercent);
        $this->assertNotNull($row->last_seen_at);

        $this->withHeaders($tok)->postJson("/api/v1/devices/{$dev}/status", ['batteryPercent' => 500])->assertStatus(422);
        $this->withHeaders(['X-Device-Token' => $this->deviceToken('TABLET_WAITER_02')])->postJson("/api/v1/devices/{$dev}/status", ['appVersion' => '1'])->assertStatus(403);

        // an admin queues a command (service level); the device drains it via status or the polling endpoint, once
        app(DeviceCommandService::class)->issue(Device::query()->find($dev), 'REFRESH_STATE', ['reason' => 'menu updated']);
        $poll = $this->withHeaders($tok)->getJson("/api/v1/devices/{$dev}/commands")->assertOk();
        $this->assertSame('REFRESH_STATE', $poll->json('items.0.command'));
        $this->assertSame('menu updated', $poll->json('items.0.payload.reason'));
        $this->withHeaders($tok)->getJson("/api/v1/devices/{$dev}/commands")->assertOk()->assertJsonPath('items', []);
    }

    public function test_revoke_kills_token_sessions_and_checkout_and_is_audited(): void
    {
        Event::fake([RealtimeEvent::class]);
        $dev = DemoIds::device('TABLET_WAITER_03');
        $tok = $this->deviceToken('TABLET_WAITER_03');
        $waiter = $this->api('wait1', $tok);
        $waiter->post("/devices/{$dev}/checkout", ['staffId' => $waiter->auth['staff']['id'], 'facilityId' => $this->facilityId('RESTAURANT')])->assertOk();

        $this->api('cashier1')->post("/devices/{$dev}/revoke")->assertStatus(403)->assertJsonPath('permission', 'device.revoke');
        $it = $this->api('itadmin1');
        $rev = $it->post("/devices/{$dev}/revoke")->assertOk();
        $this->assertSame('REVOKED', $rev->json('status'));
        $this->assertNull($rev->json('checkout'));
        $it->post("/devices/{$dev}/revoke")->assertOk(); // idempotent

        $this->withHeaders(['X-Device-Token' => $tok])->getJson("/api/v1/devices/{$dev}")->assertStatus(403)->assertJsonPath('code', 'device_not_registered'); // credential revoked
        $this->withHeaders(['Authorization' => 'Bearer '.$waiter->auth['accessToken']])->getJson('/api/v1/auth/me')->assertStatus(401); // session bound to it is dead
        $this->assertSame('FORCED', DB::table('tablet_checkout')->where('device_id', Ids::toBinary($dev))->value('status'));
        $this->assertSame(1, DB::table('device_command')->where('device_id', Ids::toBinary($dev))->where('command', 'REVOKE')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'device.revoke')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'DeviceRevoked')->count());
        Event::assertDispatched(RealtimeEvent::class, fn ($e) => $e->channel === "device.{$dev}" && $e->eventName === 'device.command' && $e->data['command'] === 'REVOKE');

        $this->api('itadmin1')->get('/devices?filter[status]=REVOKED')->assertJsonCount(1, 'items');
    }
}
