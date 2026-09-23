<?php

namespace App\Domain\Attendance\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Carbon\CarbonImmutable;

class AttendanceDevice extends Model
{
    use HasUuidV7;

    protected $table = 'attendance_device';

    protected array $uuidColumns = ['organization_id', 'site_id', 'device_id', 'facility_unit_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'last_punch_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $f = fn ($d) => $d === null ? null : CarbonImmutable::instance($d)->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $this->id, 'deviceId' => $this->device_id, 'facilityId' => $this->facility_unit_id, 'serialNumber' => $this->serial_number,
            'name' => $this->name, 'adapter' => $this->adapter, 'timeZone' => $this->time_zone, 'status' => $this->status,
            'lastSeenAt' => $f($this->last_seen_at), 'lastPunchAt' => $f($this->last_punch_at), 'rowVersion' => (int) $this->row_version,
        ];
    }
}
