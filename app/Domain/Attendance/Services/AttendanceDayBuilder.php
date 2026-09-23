<?php

namespace App\Domain\Attendance\Services;

use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Derives attendance_day from raw punches (raw punches are never modified).
 *
 * Rule set (Phase 1, documented in docs/attendance.md):
 *  - work_date is the SITE-local calendar date of the punch (site.time_zone). Overnight shifts therefore appear as two
 *    OPEN/NEEDS_REVIEW days that a supervisor fixes with a clock correction.
 *  - punches of one person within `attendance.debounce_seconds` collapse into one tap.
 *  - kept punches alternate IN,OUT,IN,OUT... regardless of the terminal's own IN/OUT flag (many terminals do not set it).
 *  - 1 punch -> OPEN; even count -> CLOSED (minutes = sum of IN..OUT pairs); odd count > 1 -> NEEDS_REVIEW.
 *  - a day already corrected by a supervisor (source MANUAL_CORRECTION) keeps its clock values; only punch_count refreshes.
 * Callers MUST hold a lock on the staff row (PunchIngestService / CorrectionService do) so concurrent rebuilds of the same
 * day serialise.
 */
class AttendanceDayBuilder
{
    /** @return array{clockIn: ?CarbonImmutable, clockOut: ?CarbonImmutable, minutes: ?int, count: int, status: string, times: list<CarbonImmutable>}|null */
    public function derive(string $staffId, string $workDate, string $tz, bool $fresh = false): ?array
    {
        [$from, $to] = $this->dayBounds($workDate, $tz);
        $rows = DB::select(
            'SELECT p.punched_at FROM attendance_punch p
               JOIN staff_biometric_link l ON l.attendance_device_id = p.attendance_device_id AND l.terminal_user_id = p.terminal_user_id AND l.is_active = 1
              WHERE l.staff_id = ? AND p.punched_at >= ? AND p.punched_at < ?
              ORDER BY p.punched_at'.($fresh ? ' FOR SHARE' : ''),
            [Ids::toBinary($staffId), $from->format('Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u')]
        );
        $debounce = (int) config('attendance.debounce_seconds');
        $kept = [];
        foreach ($rows as $r) {
            $t = CarbonImmutable::parse($r->punched_at, 'UTC');
            if ($kept !== [] && $t->getTimestamp() - end($kept)->getTimestamp() < $debounce) {
                continue;
            }
            $kept[] = $t;
        }
        $n = count($kept);
        if ($n === 0) {
            return null;
        }
        $minutes = 0;
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $minutes += intdiv($kept[$i + 1]->getTimestamp() - $kept[$i]->getTimestamp(), 60);
        }
        $status = $n === 1 ? 'OPEN' : ($n % 2 === 0 ? 'CLOSED' : 'NEEDS_REVIEW');
        $out = $n >= 2 ? $kept[$n % 2 === 0 ? $n - 1 : $n - 2] : null;

        return ['clockIn' => $kept[0], 'clockOut' => $out, 'minutes' => $n === 1 ? null : $minutes, 'count' => $n, 'status' => $status, 'times' => $kept];
    }

    /** Recompute and upsert the day. Returns the derived data (or null when there are no punches and nothing was written). */
    public function rebuild(string $organizationId, string $siteId, string $staffId, string $workDate, string $tz, bool $fresh = false): ?array
    {
        $d = $this->derive($staffId, $workDate, $tz, $fresh);
        $existing = DB::table('attendance_day')->where('staff_id', Ids::toBinary($staffId))->where('work_date', $workDate)->lockForUpdate()->first();

        if ($existing !== null && $existing->source === 'MANUAL_CORRECTION') {
            DB::table('attendance_day')->where('id', $existing->id)->update(['punch_count' => $d['count'] ?? 0]);

            return $d;
        }
        if ($d === null) {
            if ($existing !== null) { // all punches unlinked/removed: keep the row but reset it
                DB::table('attendance_day')->where('id', $existing->id)->update(['clock_in' => null, 'clock_out' => null, 'minutes_worked' => null, 'punch_count' => 0, 'status' => 'NEEDS_REVIEW', 'row_version' => $existing->row_version + 1]);
            }

            return null;
        }
        $vals = [
            'clock_in' => $d['clockIn']->format('Y-m-d H:i:s.u'), 'clock_out' => $d['clockOut']?->format('Y-m-d H:i:s.u'),
            'minutes_worked' => $d['minutes'], 'punch_count' => $d['count'], 'status' => $d['status'], 'source' => 'BIOMETRIC',
        ];
        if ($existing === null) {
            DB::table('attendance_day')->insert($vals + [
                'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($organizationId), 'site_id' => Ids::toBinary($siteId),
                'staff_id' => Ids::toBinary($staffId), 'work_date' => $workDate,
            ]);
        } else {
            DB::table('attendance_day')->where('id', $existing->id)->update($vals + ['row_version' => $existing->row_version + 1]);
        }

        return $d;
    }

    public function siteTimeZone(string $siteId): string
    {
        return (string) (DB::table('site')->where('id', Ids::toBinary($siteId))->value('time_zone') ?: 'Africa/Lagos');
    }

    public function localDate(CarbonImmutable $utc, string $tz): string
    {
        return $utc->setTimezone($tz)->format('Y-m-d');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC bounds [start, end) of the site-local date */
    public function dayBounds(string $workDate, string $tz): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $workDate.' 00:00:00', $tz);

        return [$start->utc(), $start->addDay()->utc()];
    }
}
