<?php

namespace App\Domain\Attendance\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Carbon\CarbonImmutable;

class AttendanceCorrection extends Model
{
    use HasUuidV7;

    protected $table = 'attendance_correction';

    public $timestamps = false;

    protected array $uuidColumns = ['organization_id', 'site_id', 'staff_id', 'requested_by', 'decided_by'];

    protected function casts(): array
    {
        return ['requested_clock_in' => 'datetime', 'requested_clock_out' => 'datetime', 'decided_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $f = fn ($d) => $d === null ? null : CarbonImmutable::instance($d)->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $this->id, 'staffId' => $this->staff_id, 'workDate' => $this->getRawOriginal('work_date'),
            'requestedClockIn' => $f($this->requested_clock_in), 'requestedClockOut' => $f($this->requested_clock_out),
            'reason' => $this->reason, 'status' => $this->status, 'requestedBy' => $this->requested_by, 'decidedBy' => $this->decided_by,
            'decidedAt' => $f($this->decided_at), 'decisionNote' => $this->decision_note, 'createdAt' => $f($this->created_at),
        ];
    }
}
