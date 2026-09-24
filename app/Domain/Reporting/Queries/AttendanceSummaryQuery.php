<?php

namespace App\Domain\Reporting\Queries;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Per-staff attendance totals over a local-date range, from the derived attendance_day rows. */
class AttendanceSummaryQuery
{
    /** @return array<string, mixed> */
    public function run(string $siteId, string $from, string $to, ?string $staffId): array
    {
        $q = DB::table('attendance_day as d')->join('staff as s', 's.id', '=', 'd.staff_id')
            ->where('d.site_id', Ids::toBinary($siteId))->whereBetween('d.work_date', [$from, $to])
            ->selectRaw("d.staff_id, s.staff_number, s.first_name, s.last_name, COUNT(*) days_recorded,
                         SUM(d.status = 'CLOSED') closed_days, SUM(d.status = 'OPEN') open_days, SUM(d.status = 'NEEDS_REVIEW') review_days,
                         SUM(d.source = 'MANUAL_CORRECTION') corrected_days, COALESCE(SUM(d.minutes_worked),0) minutes, MIN(d.clock_in) first_in, MAX(d.clock_out) last_out")
            ->groupBy('d.staff_id', 's.staff_number', 's.first_name', 's.last_name')->orderBy('s.staff_number');
        if ($staffId !== null) {
            $q->where('d.staff_id', Ids::toBinary($staffId));
        }
        $rows = $q->get();
        $staff = $rows->map(fn ($r) => [
            'staffId' => Ids::fromBinary($r->staff_id), 'staffNumber' => $r->staff_number, 'staffName' => trim($r->first_name.' '.$r->last_name),
            'daysRecorded' => (int) $r->days_recorded, 'closedDays' => (int) $r->closed_days, 'openDays' => (int) $r->open_days, 'needsReviewDays' => (int) $r->review_days,
            'correctedDays' => (int) $r->corrected_days, 'totalMinutes' => (int) $r->minutes,
            'averageMinutesPerClosedDay' => $r->closed_days > 0 ? intdiv((int) $r->minutes, (int) $r->closed_days) : null,
        ])->values()->all();

        return [
            'from' => $from, 'to' => $to, 'staff' => $staff,
            'totals' => ['staffCount' => count($staff), 'totalMinutes' => array_sum(array_column($staff, 'totalMinutes')), 'needsReviewDays' => array_sum(array_column($staff, 'needsReviewDays')), 'openDays' => array_sum(array_column($staff, 'openDays'))],
        ];
    }
}
