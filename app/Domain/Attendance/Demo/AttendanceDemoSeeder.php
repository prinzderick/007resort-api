<?php

namespace App\Domain\Attendance\Demo;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Services\AttendanceDayBuilder;
use App\Domain\Attendance\Services\TerminalService;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Integrated demo: the Main Gate ZKTeco terminal. Devices\Demo\DevicesDemoSeeder already registers the generic `device` row
 * ATTENDANCE_MAIN_GATE; this attaches the attendance terminal (serial ZK-DEMO-001, DEV-ONLY token) to THAT row instead of creating a
 * second device, and links the first three staff to terminal user ids 1..3 (for `r007:attendance:simulate --serial=ZK-DEMO-001`).
 */
class AttendanceDemoSeeder implements DemoSeeder
{
    public const SERIAL = 'ZK-DEMO-001';

    public const TOKEN = 'atd_dev_zk_demo_001';

    public function priority(): int
    {
        return 140;
    }

    public function run(DemoContext $context): void
    {
        $terminals = app(TerminalService::class);
        $org = Tenant::organizationId();
        $site = Tenant::siteId();
        if ($org === null || $site === null) {
            $context->info('  attendance: no tenant, skipped');

            return;
        }
        $deviceId = DemoIds::device('ATTENDANCE_MAIN_GATE');
        $device = AttendanceDevice::query()->where('serial_number', self::SERIAL)->first();
        if ($device === null) {
            $device = AttendanceDevice::create([
                'organization_id' => $org, 'site_id' => $site, 'device_id' => $deviceId, 'facility_unit_id' => DemoIds::facility('MAIN_GATE'),
                'serial_number' => self::SERIAL, 'name' => 'Main Gate Biometric Terminal (ZKTeco)', 'adapter' => 'ZKTECO_ADMS',
                'time_zone' => app(AttendanceDayBuilder::class)->siteTimeZone($site), 'token_hash' => TerminalService::hash(self::TOKEN),
            ]);
        }
        $staff = DB::table('staff')->where('site_id', Ids::toBinary($site))->where('is_active', 1)->orderBy('staff_number')->limit(3)->pluck('id');
        foreach ($staff->values() as $i => $bin) {
            $terminals->link(Ids::fromBinary($bin), $device->id, (string) ($i + 1));
        }
        $context->info('  attendance: terminal '.self::SERIAL.' (token '.self::TOKEN.") on the Main Gate device, {$staff->count()} staff linked");
    }
}
