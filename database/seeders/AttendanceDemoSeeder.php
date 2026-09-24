<?php

namespace Database\Seeders;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Services\TerminalService;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo biometric terminal ZK-DEMO-001 with the first 3 staff linked to terminal user ids 1..3, for `r007:attendance:simulate`.
 * Idempotent for the terminal; prints the device token ONCE when it creates it (dev use only - never commit it).
 *
 *   php artisan db:seed --class=Database\\Seeders\\AttendanceDemoSeeder && php artisan r007:attendance:simulate --serial=ZK-DEMO-001 --days=5
 */
class AttendanceDemoSeeder extends Seeder
{
    public function run(TerminalService $terminals): void
    {
        $site = Tenant::siteId();
        if ($site === null) {
            $this->command?->error('No site found: create the tenant first.');

            return;
        }
        $device = AttendanceDevice::query()->where('serial_number', 'ZK-DEMO-001')->first();
        if ($device === null) {
            ['device' => $device, 'token' => $token] = $terminals->register(['serialNumber' => 'ZK-DEMO-001', 'name' => 'Staff entrance (demo)', 'adapter' => 'ZKTECO_ADMS']);
            $this->command?->warn("Terminal ZK-DEMO-001 registered. Device token (shown once): {$token}");
        }
        $staff = DB::table('staff')->where('site_id', Ids::toBinary($site))->where('is_active', 1)->orderBy('staff_number')->limit(3)->pluck('id');
        foreach ($staff->values() as $i => $bin) {
            $terminals->link(Ids::fromBinary($bin), $device->id, (string) ($i + 1));
        }
        $this->command?->info("Linked {$staff->count()} staff to ZK-DEMO-001 (terminal users 1..{$staff->count()}).");
    }
}
