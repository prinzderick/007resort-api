<?php

namespace App\Domain\Attendance\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class StaffBiometricLink extends Model
{
    use HasUuidV7;

    protected $table = 'staff_biometric_link';

    protected array $uuidColumns = ['staff_id', 'attendance_device_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return ['id' => $this->id, 'staffId' => $this->staff_id, 'attendanceDeviceId' => $this->attendance_device_id, 'terminalUserId' => $this->terminal_user_id, 'active' => (bool) $this->is_active];
    }
}
