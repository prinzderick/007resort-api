<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Clock corrections: one staff member requests, a DIFFERENT holder of `staff.clock_correction.approve` decides
 * (nobody approves their own correction, nor one about themselves). Applying it writes attendance_day with source
 * MANUAL_CORRECTION, which later biometric rebuilds never overwrite. Everything is audited in the same transaction.
 */
class CorrectionService
{
    public function __construct(private readonly AttendanceDayBuilder $days) {}

    public function request(string $requestedBy, string $staffId, string $workDate, ?CarbonImmutable $in, ?CarbonImmutable $out, string $reason): AttendanceCorrection
    {
        if ($in === null && $out === null) {
            throw ApiProblem::unprocessable('validation_failed', 'Provide clockIn and/or clockOut.', [['field' => 'clockIn', 'code' => 'required', 'message' => 'clockIn or clockOut is required.']]);
        }
        if ($in !== null && $out !== null && $out <= $in) {
            throw ApiProblem::unprocessable('validation_failed', 'clockOut must be after clockIn.', [['field' => 'clockOut', 'code' => 'invalid', 'message' => 'clockOut must be after clockIn.']]);
        }

        return DB::transaction(function () use ($requestedBy, $staffId, $workDate, $in, $out, $reason): AttendanceCorrection {
            $staff = DB::table('staff')->where('id', Ids::toBinary($staffId))->first(['organization_id', 'site_id']) ?? throw ApiProblem::notFound('staff_not_found', 'Staff member not found.');
            $c = AttendanceCorrection::create([
                'organization_id' => Ids::fromBinary($staff->organization_id), 'site_id' => Ids::fromBinary($staff->site_id), 'staff_id' => $staffId, 'work_date' => $workDate,
                'requested_clock_in' => $in?->format('Y-m-d H:i:s.u'), 'requested_clock_out' => $out?->format('Y-m-d H:i:s.u'), 'reason' => $reason, 'requested_by' => $requestedBy,
            ]);
            $c->refresh();
            Audit::record('attendance.correction.request', 'AttendanceCorrection', $c->id, null, $c->toApi());

            return $c;
        });
    }

    public function decide(string $correctionId, string $decidedBy, bool $approve, ?string $note): AttendanceCorrection
    {
        return DB::transaction(function () use ($correctionId, $decidedBy, $approve, $note): AttendanceCorrection {
            $c = AttendanceCorrection::query()->lockForUpdate()->find($correctionId) ?? throw ApiProblem::notFound('correction_not_found', 'Correction not found.');
            if ($c->status !== 'PENDING') {
                throw ApiProblem::conflict('correction_already_decided', "Correction is already {$c->status}.");
            }
            if ($c->requested_by === Ids::normalize($decidedBy) || $c->staff_id === Ids::normalize($decidedBy)) {
                throw ApiProblem::forbidden('self_approval_forbidden', 'A correction must be decided by someone other than its requester or subject.');
            }
            $before = $c->toApi();
            $c->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'decided_by' => $decidedBy, 'decided_at' => CarbonImmutable::now('UTC'), 'decision_note' => $note]);
            if ($approve) {
                $this->apply($c);
            }
            Audit::record($approve ? 'attendance.correction.approve' : 'attendance.correction.reject', 'AttendanceCorrection', $c->id, $before, $c->refresh()->toApi(), organizationId: $c->organization_id, siteId: $c->site_id);

            return $c;
        });
    }

    private function apply(AttendanceCorrection $c): void
    {
        DB::table('staff')->where('id', Ids::toBinary($c->staff_id))->lockForUpdate()->get(['id']);
        $workDate = (string) $c->getRawOriginal('work_date');
        $existing = DB::table('attendance_day')->where('staff_id', Ids::toBinary($c->staff_id))->where('work_date', $workDate)->lockForUpdate()->first();
        $in = $c->requested_clock_in !== null ? CarbonImmutable::instance($c->requested_clock_in) : ($existing?->clock_in ? CarbonImmutable::parse($existing->clock_in, 'UTC') : null);
        $out = $c->requested_clock_out !== null ? CarbonImmutable::instance($c->requested_clock_out) : ($existing?->clock_out ? CarbonImmutable::parse($existing->clock_out, 'UTC') : null);
        if ($in !== null && $out !== null && $out <= $in) {
            throw ApiProblem::unprocessable('validation_failed', 'The correction would put clock-out before clock-in.');
        }
        $vals = [
            'clock_in' => $in?->format('Y-m-d H:i:s.u'), 'clock_out' => $out?->format('Y-m-d H:i:s.u'),
            'minutes_worked' => ($in && $out) ? intdiv($out->getTimestamp() - $in->getTimestamp(), 60) : null,
            'status' => ($in && $out) ? 'CLOSED' : 'OPEN', 'source' => 'MANUAL_CORRECTION',
        ];
        if ($existing === null) {
            DB::table('attendance_day')->insert($vals + [
                'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($c->organization_id), 'site_id' => Ids::toBinary($c->site_id),
                'staff_id' => Ids::toBinary($c->staff_id), 'work_date' => $workDate, 'punch_count' => 0,
            ]);
        } else {
            DB::table('attendance_day')->where('id', $existing->id)->update($vals + ['row_version' => $existing->row_version + 1]);
        }
        Outbox::record('AttendanceCorrected', 'Staff', $c->staff_id, [
            'staffId' => $c->staff_id, 'workDate' => $workDate, 'clockIn' => $in?->format('Y-m-d\TH:i:s.v\Z'), 'clockOut' => $out?->format('Y-m-d\TH:i:s.v\Z'), 'correctionId' => $c->id,
        ], organizationId: $c->organization_id, siteId: $c->site_id);
    }
}
