<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Support\ParsedPunch;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Vendor-neutral punch ingestion. Append-only + idempotent: the UNIQUE (device, terminal user id, punched_at) key makes a
 * replayed or overlapping batch (terminals resend whole logs after a reboot) count as `duplicates`, never as new rows.
 * Staff resolution happens at read time via staff_biometric_link; attendance_day is rebuilt for every affected
 * (staff, local date) under a lock on the staff row (ordered, so concurrent batches cannot deadlock or lose a punch).
 */
class PunchIngestService
{
    public function __construct(private readonly AttendanceDayBuilder $days) {}

    /**
     * @param  list<ParsedPunch>  $punches
     * @return array{accepted: int, duplicates: int, unmapped: int}
     */
    public function ingest(AttendanceDevice $device, array $punches): array
    {
        $deviceBin = Ids::toBinary($device->id);
        $tz = $this->days->siteTimeZone($device->site_id);

        return DB::transaction(function () use ($device, $punches, $deviceBin, $tz): array {
            // FIRST statements of the transaction are LOCKING reads (share lock on the few link rows, then exclusive lock on the
            // staff rows), which do not open the REPEATABLE READ snapshot. The snapshot is only taken by the first plain SELECT
            // (inside rebuild()), i.e. AFTER the per-staff locks are held, so a rebuild always sees every punch committed by the
            // racer that held the lock before us. (A plain SELECT here would freeze the view early and lose those punches.)
            $links = DB::table('staff_biometric_link')->where('attendance_device_id', $deviceBin)->where('is_active', 1)->lock('lock in share mode')->pluck('staff_id', 'terminal_user_id')->all();

            // Lock every mapped staff row up front, in id order (consistent lock order => no deadlocks between concurrent batches).
            $staffIds = [];
            foreach ($punches as $p) {
                if (isset($links[$p->terminalUserId])) {
                    $staffIds[Ids::fromBinary($links[$p->terminalUserId])] = $links[$p->terminalUserId];
                }
            }
            ksort($staffIds);
            if ($staffIds !== []) {
                DB::table('staff')->whereIn('id', array_values($staffIds))->orderBy('id')->lockForUpdate()->get(['id']);
            }

            $accepted = $duplicates = $unmapped = 0;
            $touched = [];   // "staffId|date" => [staffId, date]
            $newMapped = []; // [ParsedPunch, staffId, date]
            $latest = null;
            foreach ($punches as $p) {
                $inserted = DB::table('attendance_punch')->insertOrIgnore([
                    'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($device->organization_id), 'attendance_device_id' => $deviceBin,
                    'terminal_user_id' => $p->terminalUserId, 'punched_at' => $p->punchedAt->format('Y-m-d H:i:s.u'), 'verify_mode' => $p->verifyMode,
                    'direction' => $p->direction, 'raw_id' => $p->rawId, 'received_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
                if ($inserted === 0) {
                    $duplicates++;

                    continue;
                }
                $accepted++;
                $latest = $latest === null || $p->punchedAt > $latest ? $p->punchedAt : $latest;
                if (! isset($links[$p->terminalUserId])) {
                    $unmapped++;

                    continue;
                }
                $staffId = Ids::fromBinary($links[$p->terminalUserId]);
                $date = $this->days->localDate($p->punchedAt, $tz);
                $touched["{$staffId}|{$date}"] = [$staffId, $date];
                $newMapped[] = [$p, $staffId, $date];
            }

            $derived = [];
            foreach ($touched as $key => [$staffId, $date]) {
                $derived[$key] = $this->days->rebuild($device->organization_id, $device->site_id, $staffId, $date, $tz);
            }

            foreach ($newMapped as [$p, $staffId, $date]) {
                $kind = $p->direction;
                if ($kind === 'UNKNOWN') {
                    $times = array_map(fn (CarbonImmutable $t) => $t->getTimestamp(), $derived["{$staffId}|{$date}"]['times'] ?? []);
                    $idx = array_search($p->punchedAt->getTimestamp(), $times, true);
                    $kind = ($idx === false || $idx % 2 === 0) ? 'IN' : 'OUT';
                }
                Outbox::record($kind === 'IN' ? 'StaffClockedIn' : 'StaffClockedOut', 'Staff', $staffId, [
                    'staffId' => $staffId, 'deviceId' => $device->id, 'attendanceDeviceId' => $device->id, 'punchedAt' => $p->punchedAt->format('Y-m-d\TH:i:s.v\Z'),
                    'workDate' => $date, 'verifyMode' => $p->verifyMode, 'facilityId' => $device->facility_unit_id,
                ], organizationId: $device->organization_id, siteId: $device->site_id, facilityId: $device->facility_unit_id);
            }

            $touch = ['last_seen_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')];
            if ($latest !== null) {
                $touch['last_punch_at'] = DB::raw("GREATEST(COALESCE(last_punch_at, '1970-01-01'), '{$latest->format('Y-m-d H:i:s.u')}')");
            }
            DB::table('attendance_device')->where('id', $deviceBin)->update($touch);

            return ['accepted' => $accepted, 'duplicates' => $duplicates, 'unmapped' => $unmapped];
        });
    }
}
