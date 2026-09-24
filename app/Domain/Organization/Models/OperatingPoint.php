<?php

namespace App\Domain\Organization\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class OperatingPoint extends Model
{
    use HasUuidV7;

    protected $table = 'operating_point';

    protected array $uuidColumns = ['organization_id', 'site_id', 'facility_unit_id', 'default_prep_station_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'facilityId' => $this->facility_unit_id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind,
            'defaultPrepStationId' => $this->default_prep_station_id,
        ];
    }
}
