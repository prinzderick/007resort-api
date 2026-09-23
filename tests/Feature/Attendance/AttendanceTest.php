<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\MembersFixtures;
use Tests\Support\TestData;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use MembersFixtures;

    private string $mgr;

    private $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        [, $this->mgr] = $this->actor('mgr');
        $this->alice = TestData::staff($this->t, 'alice');
    }

    private function h(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->mgr), 'Accept' => 'application/json'];
    }

    /** @return array{0: string, 1: string, 2: string} [attendanceDeviceId, serial, deviceToken] */
    private function terminal(string $serial = 'ZK-TEST-001', string $adapter = 'ZKTECO_ADMS'): array
    {
        $r = $this->postJson('/api/v1/attendance/devices', ['serialNumber' => $serial, 'name' => 'Staff gate', 'adapter' => $adapter], $this->h())->assertCreated();

        return [$r->json('device.id'), $serial, $r->json('deviceToken')];
    }

    private function link(string $deviceId, string $pin, $staff = null)
    {
        return $this->postJson('/api/v1/attendance/links', ['staffId' => ($staff ?? $this->alice)->id, 'attendanceDeviceId' => $deviceId, 'terminalUserId' => $pin], $this->idem($this->mgr));
    }

    private function push(string $serial, string $token, array $punches)
    {
        return $this->postJson('/api/v1/attendance/punches', ['terminalSerial' => $serial, 'punches' => $punches], ['X-Device-Token' => $token, 'Accept' => 'application/json']);
    }

    private function p(string $pin, string $at, string $raw): array
    {
        return ['deviceUserId' => $pin, 'punchedAt' => $at, 'rawId' => $raw, 'verifyMode' => 'FINGERPRINT'];
    }

    public function test_terminal_registration_returns_token_once_and_stores_only_a_hash_and_a_device_row(): void
    {
        [$id, $serial, $token] = $this->terminal();
        $this->assertStringStartsWith('atd_', $token);
        $row = DB::table('attendance_device')->where('id', Ids::toBinary($id))->first();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame('BIOMETRIC_TERMINAL', DB::table('device')->where('id', $row->device_id)->value('device_type'));
        $this->assertNotNull(DB::table('audit_log')->where('action', 'attendance.device.register')->first());
        $this->getJson('/api/v1/attendance/devices', $this->h())->assertOk()->assertJsonMissingPath('items.0.deviceToken')->assertJsonMissingPath('items.0.tokenHash');
        $this->postJson('/api/v1/attendance/devices', ['serialNumber' => $serial, 'name' => 'dup'], $this->h())->assertStatus(409)->assertJsonPath('code', 'terminal_serial_taken');
    }

    public function test_json_ingest_dedups_replays_and_counts_unmapped(): void
    {
        [$dev, $serial, $tok] = $this->terminal();
        $this->link($dev, '1')->assertCreated();

        $batch = [$this->p('1', '2026-09-22T08:00:00+01:00', 'r1'), $this->p('1', '2026-09-22T17:00:00+01:00', 'r2'), $this->p('99', '2026-09-22T08:05:00+01:00', 'r3')];
        $this->push($serial, $tok, $batch)->assertOk()->assertExactJson(['accepted' => 3, 'duplicates' => 0, 'unmapped' => 1]);
        $this->push($serial, $tok, $batch)->assertOk()->assertExactJson(['accepted' => 0, 'duplicates' => 3, 'unmapped' => 0]);
        // same instant expressed in UTC is the same punch
        $this->push($serial, $tok, [$this->p('1', '2026-09-22T07:00:00Z', 'other-raw-id')])->assertOk()->assertJsonPath('duplicates', 1);
        $this->assertSame(3, DB::table('attendance_punch')->count());

        $day = $this->getJson('/api/v1/attendance?filter[staffId]='.$this->alice->id, $this->h())->assertOk()->json('items.0');
        $this->assertSame('CLOSED', $day['status']);
        $this->assertSame(540, $day['minutesWorked']);
        $this->assertSame('2026-09-22', $day['workDate']);
        $this->assertSame('Alice Tester', $day['staffName']);
        $this->assertSame('BIOMETRIC', $day['source']);
        $this->assertSame('2026-09-22T07:00:00.000Z', $day['clockIn']);

        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'StaffClockedIn')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'StaffClockedOut')->count());
    }

    public function test_terminal_authentication_failures(): void
    {
        [$dev, $serial, $tok] = $this->terminal();
        $body = ['terminalSerial' => $serial, 'punches' => [$this->p('1', '2026-09-22T08:00:00Z', 'a')]];
        $this->postJson('/api/v1/attendance/punches', $body, ['Accept' => 'application/json'])->assertStatus(401);
        $this->postJson('/api/v1/attendance/punches', $body, ['X-Device-Token' => 'atd_nope', 'Accept' => 'application/json'])->assertStatus(401)->assertJsonPath('code', 'device_not_registered');
        // a staff bearer token is not accepted here
        $this->postJson('/api/v1/attendance/punches', $body, $this->h())->assertStatus(401);
        // token of terminal A cannot push for terminal B's serial
        $this->push('OTHER-SN', $tok, $body['punches'])->assertStatus(403)->assertJsonPath('code', 'terminal_mismatch');
        $this->push($serial, $tok, [['deviceUserId' => '1']])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->postJson("/api/v1/attendance/devices/{$dev}/status", ['status' => 'DISABLED'], $this->idem($this->mgr))->assertOk();
        $this->push($serial, $tok, $body['punches'])->assertStatus(403)->assertJsonPath('code', 'device_revoked');
        $this->postJson("/api/v1/attendance/devices/{$dev}/status", ['status' => 'ACTIVE'], $this->idem($this->mgr))->assertOk();
        $this->push($serial, $tok, $body['punches'])->assertOk();

        $new = $this->postJson("/api/v1/attendance/devices/{$dev}/rotate-token", [], $this->h())->assertOk()->json('deviceToken');
        $this->push($serial, $tok, $body['punches'])->assertStatus(401);
        $this->push($serial, $new, $body['punches'])->assertOk();
    }

    public function test_zkteco_adms_handshake_upload_and_polling(): void
    {
        [$dev, $serial] = $this->terminal();
        $this->link($dev, '1')->assertCreated();
        $this->link($dev, '2', TestData::staff($this->t, 'bob'))->assertCreated();

        $hs = $this->call('GET', '/iclock/cdata', ['SN' => $serial, 'options' => 'all', 'pushver' => '2.4.1']);
        $hs->assertOk();
        $this->assertStringStartsWith('text/plain', $hs->headers->get('Content-Type'));
        $this->assertStringContainsString('ATTLOGStamp=None', $hs->getContent());
        $this->assertNotNull(AttendanceDevice::find($dev)->last_seen_at);

        $body = (string) file_get_contents(__DIR__.'/../../Fixtures/Attendance/attlog_tab.txt');
        $up = $this->call('POST', '/iclock/cdata?SN='.$serial.'&table=ATTLOG&Stamp=777', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
        $up->assertOk();
        $this->assertSame('OK: 5', $up->getContent());
        $this->assertSame(5, DB::table('attendance_punch')->count());
        $this->call('POST', '/iclock/cdata?SN='.$serial.'&table=ATTLOG&Stamp=777', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertOk();
        $this->assertSame(5, DB::table('attendance_punch')->count(), 'terminal resend is a no-op');
        $this->assertStringContainsString('ATTLOGStamp=777', $this->call('GET', '/iclock/cdata', ['SN' => $serial])->getContent());

        $days = $this->getJson('/api/v1/attendance', $this->h())->assertOk()->json('items');
        $this->assertCount(2, $days);
        $this->assertSame(549, collect($days)->firstWhere('staffName', 'Alice Tester')['minutesWorked']);
        $this->assertSame(Ids::fromBinary(DB::table('staff')->where('staff_number', 'BOB')->value('id')), collect($days)->firstWhere('staffName', 'Bob Tester')['staffId']);

        $this->call('POST', '/iclock/cdata?SN='.$serial.'&table=OPERLOG', [], [], [], [], "OPLOG 4\t0\t0\t0")->assertOk();
        $this->assertSame('OK', $this->call('GET', '/iclock/getrequest', ['SN' => $serial])->getContent());
        $this->assertSame('OK', $this->call('POST', '/iclock/devicecmd?SN='.$serial)->getContent());
        $this->call('GET', '/iclock/getrequest', ['SN' => 'UNREGISTERED'])->assertStatus(403);
        $this->call('GET', '/iclock/cdata')->assertStatus(403);
        $this->call('POST', '/iclock/cdata?SN=UNREGISTERED&table=ATTLOG', [], [], [], [], $body)->assertStatus(403);
    }

    public function test_iclock_source_allow_list(): void
    {
        [, $serial] = $this->terminal();
        config(['attendance.iclock_allowed_cidrs' => ['10.20.0.0/16']]);
        $this->call('GET', '/iclock/getrequest', ['SN' => $serial])->assertStatus(403);
        $this->call('GET', '/iclock/getrequest', ['SN' => $serial], [], [], ['REMOTE_ADDR' => '10.20.3.4'])->assertOk();
    }

    public function test_day_derivation_states_debounce_and_local_date_boundary(): void
    {
        [$dev, $serial, $tok] = $this->terminal();
        $this->link($dev, '1');
        $days = fn () => DB::table('attendance_day')->orderBy('work_date')->get();

        // one punch => OPEN
        $this->push($serial, $tok, [$this->p('1', '2026-09-22T08:00:00+01:00', 'a')])->assertOk();
        $this->assertSame(['OPEN'], $days()->pluck('status')->all());
        $this->assertNull($days()[0]->minutes_worked);

        // double tap within 120s collapses; OUT closes the day
        $this->push($serial, $tok, [$this->p('1', '2026-09-22T08:00:30+01:00', 'b'), $this->p('1', '2026-09-22T16:30:00+01:00', 'c')])->assertOk();
        $d = $days()[0];
        $this->assertSame('CLOSED', $d->status);
        $this->assertSame(2, (int) $d->punch_count);
        $this->assertSame(510, (int) $d->minutes_worked);

        // a third punch that day => NEEDS_REVIEW
        $this->push($serial, $tok, [$this->p('1', '2026-09-22T18:00:00+01:00', 'd')])->assertOk();
        $this->assertSame('NEEDS_REVIEW', $days()[0]->status);

        // 23:30 and 00:30 Lagos are different LOCAL dates although 22:30Z/23:30Z are the same UTC date
        $this->push($serial, $tok, [$this->p('1', '2026-09-23T23:30:00+01:00', 'e'), $this->p('1', '2026-09-24T00:30:00+01:00', 'f')])->assertOk();
        $this->assertSame(['2026-09-22', '2026-09-23', '2026-09-24'], $days()->pluck('work_date')->all());
    }

    public function test_late_link_retroactively_attributes_earlier_punches_and_unlink_detaches(): void
    {
        [$dev, $serial, $tok] = $this->terminal();
        $this->push($serial, $tok, [$this->p('5', '2026-09-22T08:00:00+01:00', 'a'), $this->p('5', '2026-09-22T17:00:00+01:00', 'b')])->assertOk()->assertJsonPath('unmapped', 2);
        $this->assertSame(0, DB::table('attendance_day')->count());
        $linkId = $this->link($dev, '5')->assertCreated()->json('id');
        $this->assertSame('CLOSED', DB::table('attendance_day')->value('status'));
        $this->link($dev, '5', TestData::staff($this->t, 'carol'))->assertStatus(409)->assertJsonPath('code', 'terminal_user_taken');
        $this->deleteJson("/api/v1/attendance/links/{$linkId}", [], $this->idem($this->mgr))->assertOk()->assertJsonPath('active', false);
        $this->assertSame(0, (int) DB::table('attendance_day')->value('punch_count'));
        $this->assertSame(2, DB::table('attendance_punch')->count(), 'raw punches are never touched');
    }

    public function test_correction_workflow_separation_of_duties_and_persistence(): void
    {
        [$dev, $serial, $tok] = $this->terminal();
        $this->link($dev, '1');
        $this->push($serial, $tok, [$this->p('1', '2026-09-22T08:00:00+01:00', 'a')])->assertOk(); // forgot to clock out
        [, $sup] = $this->actor('sup', 'UNIT_SUPERVISOR');
        [$reqStaff, $reqTok] = $this->actor('clerk', 'UNIT_SUPERVISOR');

        $c = $this->postJson('/api/v1/attendance/corrections', [
            'staffId' => $this->alice->id, 'workDate' => '2026-09-22', 'clockOut' => '2026-09-22T17:00:00+01:00', 'reason' => 'Forgot to clock out',
        ], $this->idem($reqTok))->assertCreated()->assertJsonPath('status', 'PENDING')->json();

        // requester cannot approve their own request; nor can the subject
        $this->postJson("/api/v1/attendance/corrections/{$c['id']}/approve", [], $this->idem($reqTok))->assertStatus(403)->assertJsonPath('code', 'self_approval_forbidden');
        [$aliceStaff] = [$this->alice];
        TestData::assign($aliceStaff, 'MANAGER');
        $this->postJson("/api/v1/attendance/corrections/{$c['id']}/approve", [], $this->idem($this->login('alice')))->assertStatus(403)->assertJsonPath('code', 'self_approval_forbidden');
        // permission-less staff cannot decide
        [, $cash] = $this->actor('cashier', 'CASHIER');
        $this->postJson("/api/v1/attendance/corrections/{$c['id']}/approve", [], $this->idem($cash))->assertStatus(403)->assertJsonPath('code', 'permission_denied');

        $this->postJson("/api/v1/attendance/corrections/{$c['id']}/approve", ['note' => 'verified with supervisor'], $this->idem($sup))->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson("/api/v1/attendance/corrections/{$c['id']}/reject", [], $this->idem($sup))->assertStatus(409)->assertJsonPath('code', 'correction_already_decided');

        $day = $this->getJson('/api/v1/attendance', $this->h())->json('items.0');
        $this->assertSame('MANUAL_CORRECTION', $day['source']);
        $this->assertSame('CLOSED', $day['status']);
        $this->assertSame(540, $day['minutesWorked']);

        // a later biometric punch does not overwrite the supervisor's numbers
        $this->push($serial, $tok, [$this->p('1', '2026-09-22T19:00:00+01:00', 'z')])->assertOk();
        $day = $this->getJson('/api/v1/attendance', $this->h())->json('items.0');
        $this->assertSame(540, $day['minutesWorked']);
        $this->assertSame(2, $day['punchCount']);

        $this->assertNotNull(DB::table('audit_log')->where('action', 'attendance.correction.approve')->first());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'AttendanceCorrected')->count());
        $this->assertTrue(Audit::verifyChain()->valid);

        // rejection path + validation
        $c2 = $this->postJson('/api/v1/attendance/corrections', ['staffId' => $this->alice->id, 'workDate' => '2026-09-21', 'clockIn' => '2026-09-21T08:00:00Z', 'clockOut' => '2026-09-21T07:00:00Z', 'reason' => 'bad times'], $this->idem($reqTok));
        $c2->assertStatus(422);
        $c3 = $this->postJson('/api/v1/attendance/corrections', ['staffId' => $this->alice->id, 'workDate' => '2026-09-21', 'clockIn' => '2026-09-21T08:00:00Z', 'reason' => 'no record at all'], $this->idem($reqTok))->assertCreated()->json();
        $this->postJson("/api/v1/attendance/corrections/{$c3['id']}/reject", ['note' => 'not credible'], $this->idem($sup))->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertSame(1, DB::table('attendance_day')->where('work_date', '2026-09-21')->count() + 1, 'rejected correction changes nothing');
        $this->getJson('/api/v1/attendance/corrections?status=PENDING', $this->h())->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_permission_denials(): void
    {
        [$dev, $serial] = $this->terminal();
        [, $waiter] = $this->actor('waiter', 'WAIT_STAFF');
        $h = $this->h($waiter);
        $this->getJson('/api/v1/attendance', $h)->assertStatus(403)->assertJsonPath('permission', 'attendance.view');
        $this->getJson('/api/v1/attendance/punches', $h)->assertStatus(403);
        $this->getJson('/api/v1/attendance/devices', $h)->assertStatus(403)->assertJsonPath('permission', 'attendance.device.manage');
        $this->postJson('/api/v1/attendance/devices', ['serialNumber' => 'X', 'name' => 'x'], $h)->assertStatus(403);
        $this->postJson('/api/v1/attendance/links', ['staffId' => $this->alice->id, 'attendanceDeviceId' => $dev, 'terminalUserId' => '1'], $this->idem($waiter))->assertStatus(403);
        $this->postJson('/api/v1/attendance/corrections', ['staffId' => $this->alice->id, 'workDate' => '2026-09-22', 'clockIn' => '2026-09-22T08:00:00Z', 'reason' => 'nope nope'], $this->idem($waiter))->assertStatus(403);
        $this->getJson('/api/v1/attendance', ['Accept' => 'application/json'])->assertStatus(401);
        // a role NAMED Manager without the permission is still denied (permission-based)
        $staff = TestData::staff($this->t, 'impostor');
        TestData::assignRole($staff, TestData::customRole('MANAGER2', 'Manager', ['order.create']));
        $this->getJson('/api/v1/attendance', $this->h($this->login('impostor')))->assertStatus(403);
    }

    public function test_attendance_filters_and_pagination(): void
    {
        [$dev, $serial, $tok] = $this->terminal();
        $this->link($dev, '1');
        for ($i = 1; $i <= 5; $i++) {
            $d = sprintf('2026-09-%02d', 10 + $i);
            $this->push($serial, $tok, [$this->p('1', "{$d}T08:00:00+01:00", "in{$i}"), $this->p('1', "{$d}T17:00:00+01:00", "out{$i}")])->assertOk();
        }
        $page1 = $this->getJson('/api/v1/attendance?limit=2', $this->h())->assertOk();
        $this->assertSame(['2026-09-15', '2026-09-14'], array_column($page1->json('items'), 'workDate'));
        $page2 = $this->getJson('/api/v1/attendance?limit=2&cursor='.$page1->json('nextCursor'), $this->h())->assertOk();
        $this->assertSame(['2026-09-13', '2026-09-12'], array_column($page2->json('items'), 'workDate'));
        $this->getJson('/api/v1/attendance?filter[from]=2026-09-13&filter[to]=2026-09-14', $this->h())->assertJsonCount(2, 'items');
        $this->getJson('/api/v1/attendance?filter[status]=OPEN', $this->h())->assertJsonCount(0, 'items');
        $this->getJson('/api/v1/attendance?filter[from]=nope', $this->h())->assertStatus(422);
        $this->getJson('/api/v1/attendance/punches?limit=3', $this->h())->assertOk()->assertJsonCount(3, 'items');
    }

    public function test_simulator_command_generates_punches_through_the_adms_path(): void
    {
        [$dev, $serial] = $this->terminal();
        $this->link($dev, '1');
        $this->link($dev, '2', TestData::staff($this->t, 'bob'));
        $this->artisan('r007:attendance:simulate', ['--serial' => $serial, '--days' => 3, '--seed' => 42])->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('attendance_punch')->count());
        $this->assertGreaterThan(0, DB::table('attendance_day')->count());
        $this->artisan('r007:attendance:simulate', ['--serial' => 'NOPE'])->assertFailed();
    }
}
