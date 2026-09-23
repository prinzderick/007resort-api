<?php

namespace App\Domain\Devices\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use App\Support\Money\MoneyString;

class TabletCheckout extends Model
{
    use HasUuidV7;

    protected $table = 'tablet_checkout';

    protected array $uuidColumns = ['organization_id', 'site_id', 'device_id', 'staff_id', 'facility_unit_id', 'shift_id', 'checked_out_by', 'checked_in_by', 'cash_session_id'];

    protected function casts(): array
    {
        return ['opening_float' => MoneyString::class, 'checked_out_at' => 'datetime', 'checked_in_at' => 'datetime'];
    }

    /** contract DeviceCheckout (+ extras). @return array<string, mixed> */
    public function toApi(): array
    {
        $fmt = fn ($d) => $d?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $this->id, 'staffId' => $this->staff_id, 'facilityId' => $this->facility_unit_id, 'shiftId' => $this->shift_id,
            'checkedOutAt' => $fmt($this->checked_out_at), 'checkedInAt' => $fmt($this->checked_in_at),
            'openingFloat' => $this->opening_float, 'status' => $this->status,
        ];
    }
}
