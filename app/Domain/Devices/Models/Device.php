<?php

namespace App\Domain\Devices\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class Device extends Model
{
    use HasUuidV7;

    /** contract DeviceKind => DB device_type */
    public const KIND_TO_TYPE = [
        'MOBILE_TABLET' => 'TABLET',
        'POS_TERMINAL' => 'POS',
        'KDS_SCREEN' => 'KDS',
        'ENTRANCE_SCANNER' => 'SCANNER',
        'ATTENDANCE_TERMINAL' => 'BIOMETRIC_TERMINAL',
        'SERVER' => 'OTHER',
    ];

    protected $table = 'device';

    protected array $uuidColumns = ['organization_id', 'site_id', 'facility_unit_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_revoked' => 'boolean', 'revoked_at' => 'datetime', 'last_seen_at' => 'datetime', 'last_status' => 'array'];
    }

    public function kind(): string
    {
        return array_search($this->device_type, self::KIND_TO_TYPE, true) ?: 'SERVER';
    }

    public function status(): string
    {
        return $this->is_revoked ? 'REVOKED' : ($this->is_active ? 'ACTIVE' : 'PENDING');
    }
}
