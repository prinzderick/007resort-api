<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\StaffBiometricLink;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\MembersFixtures;
use Tests\Support\MemberWorkers;
use Tests\Support\TestData;

class PunchRaceTest extends ConcurrentTestCase
{
    use MembersFixtures;

    public function test_concurrent_identical_batches_are_stored_once_and_count_once(): void
    {
        $this->bootTenant();
        $staff = TestData::staff($this->t, 'racer');
        $dev = AttendanceDevice::create([
            'organization_id' => $this->t['org'], 'site_id' => $this->t['site'], 'serial_number' => 'ZK-RACE', 'name' => 'Race', 'adapter' => 'ZKTECO_ADMS',
            'time_zone' => 'Africa/Lagos', 'token_hash' => hash('sha256', 'x'),
        ]);
        StaffBiometricLink::create(['staff_id' => $staff->id, 'attendance_device_id' => $dev->id, 'terminal_user_id' => '7']);
        $lines = [];
        for ($i = 0; $i < 6; $i++) {
            $lines[] = "7\t2026-09-22 ".sprintf('%02d', 8 + $i).":00:00\t".($i % 2)."\t1\t0\t0";
        }

        $results = Concurrent::run(8, MemberWorkers::class, 'ingestBatch', [$dev->id, implode("\n", $lines)]);

        $accepted = $dupes = 0;
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $accepted += $r['result']['accepted'];
            $dupes += $r['result']['duplicates'];
        }
        $this->assertSame(6, $accepted, 'each punch is accepted by exactly one racer');
        $this->assertSame(8 * 6 - 6, $dupes);
        $this->assertSame(6, DB::table('attendance_punch')->count());
        $day = DB::table('attendance_day')->first();
        $this->assertSame(6, (int) $day->punch_count);
        $this->assertSame('CLOSED', $day->status);
        $this->assertSame(1, DB::table('attendance_day')->count());
    }

    public function test_concurrent_partial_batches_lose_no_punch_in_the_derived_day(): void
    {
        $this->bootTenant();
        $staff = TestData::staff($this->t, 'racer2');
        $dev = AttendanceDevice::create([
            'organization_id' => $this->t['org'], 'site_id' => $this->t['site'], 'serial_number' => 'ZK-RACE2', 'name' => 'Race2', 'adapter' => 'ZKTECO_ADMS',
            'time_zone' => 'Africa/Lagos', 'token_hash' => hash('sha256', 'y'),
        ]);
        StaffBiometricLink::create(['staff_id' => $staff->id, 'attendance_device_id' => $dev->id, 'terminal_user_id' => '8']);
        // 4 racers each push a DIFFERENT pair of punches for the same day; the day must reflect all 8.
        $results = [];
        $procs = [];
        foreach ([0, 1, 2, 3] as $i) {
            $procs[$i] = "8\t2026-09-22 ".sprintf('%02d', 6 + $i * 3).":00:00\t0\t1\n8\t2026-09-22 ".sprintf('%02d', 7 + $i * 3).":00:00\t1\t1";
        }
        $results = Concurrent::run(4, MemberWorkers::class, 'ingestIndexed', [$dev->id, $procs]);
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
        }
        $this->assertSame(8, DB::table('attendance_punch')->count());
        $this->assertSame(8, (int) DB::table('attendance_day')->value('punch_count'));
        $this->assertSame('CLOSED', DB::table('attendance_day')->value('status'));
    }
}
