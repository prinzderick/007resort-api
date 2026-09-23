<?php

namespace App\Domain\Attendance\Console;

use App\Domain\Attendance\Adapters\ZktecoAdmsAdapter;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Services\PunchIngestService;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Demo/QA generator: pretends to be a ZKTeco terminal and pushes realistic ATTLOG data
 * (arrive ~08:00, leave ~17:00 local, occasional forgotten clock-out, occasional double tap).
 *
 *   php artisan r007:attendance:simulate --serial=ZK-DEMO-001 --days=5
 *   php artisan r007:attendance:simulate --serial=ZK-DEMO-001 --url=http://127.0.0.1:8007   # goes through the real /iclock/cdata HTTP path
 * Uses the terminal user ids that are linked to staff on that terminal (or --users=1,2,3).
 */
class SimulatePunchesCommand extends Command
{
    protected $signature = 'r007:attendance:simulate {--serial= : registered terminal serial number} {--days=1 : number of past days incl. today} {--users= : comma list of terminal user ids (default: all linked)} {--url= : POST to a running server instead of ingesting in-process} {--seed= : deterministic RNG seed}';

    protected $description = 'Simulate a ZKTeco biometric terminal pushing attendance punches (demo/QA).';

    public function handle(ZktecoAdmsAdapter $adapter, PunchIngestService $ingest): int
    {
        $serial = (string) $this->option('serial');
        $device = AttendanceDevice::query()->where('serial_number', $serial)->first();
        if ($device === null) {
            $this->error("No registered terminal with serial '{$serial}'. Register one via POST /api/v1/attendance/devices (or the demo seeder).");

            return self::FAILURE;
        }
        if ($this->option('seed') !== null) {
            mt_srand((int) $this->option('seed'));
        }
        $users = $this->option('users')
            ? array_map('trim', explode(',', (string) $this->option('users')))
            : DB::table('staff_biometric_link')->where('attendance_device_id', Ids::toBinary($device->id))->where('is_active', 1)->pluck('terminal_user_id')->all();
        if ($users === []) {
            $this->error('No terminal users: link staff first (POST /api/v1/attendance/links) or pass --users.');

            return self::FAILURE;
        }

        $tz = $device->time_zone;
        $lines = [];
        for ($d = (int) $this->option('days') - 1; $d >= 0; $d--) {
            $day = CarbonImmutable::now($tz)->subDays($d)->startOfDay();
            if ($day->isSunday()) {
                continue;
            }
            foreach ($users as $u) {
                $in = $day->setTime(7, 45)->addMinutes(mt_rand(0, 45))->addSeconds(mt_rand(0, 59));
                $lines[] = $this->attlog($u, $in, 0);
                if (mt_rand(1, 100) <= 15) {
                    $lines[] = $this->attlog($u, $in->addSeconds(mt_rand(5, 40)), 0); // double tap
                }
                if (mt_rand(1, 100) > 8 && ($d > 0 || CarbonImmutable::now($tz) > $day->setTime(18, 0))) {
                    $lines[] = $this->attlog($u, $day->setTime(16, 30)->addMinutes(mt_rand(0, 90))->addSeconds(mt_rand(0, 59)), 1);
                }
            }
        }
        $body = implode("\n", $lines)."\n";

        if ($url = $this->option('url')) {
            $res = Http::withBody($body, 'text/plain')->post(rtrim((string) $url, '/').'/iclock/cdata?SN='.urlencode($serial).'&table=ATTLOG&Stamp='.time());
            $this->info('HTTP '.$res->status().' '.trim($res->body()));

            return $res->successful() ? self::SUCCESS : self::FAILURE;
        }
        $r = $ingest->ingest($device, $adapter->parsePunches($body, $device));
        $this->info(sprintf('lines=%d accepted=%d duplicates=%d unmapped=%d', count($lines), $r['accepted'], $r['duplicates'], $r['unmapped']));

        return self::SUCCESS;
    }

    private function attlog(string $pin, CarbonImmutable $t, int $status): string
    {
        return implode("\t", [$pin, $t->format('Y-m-d H:i:s'), $status, 1, 0, 0]);
    }
}
