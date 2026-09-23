<?php

namespace App\Domain\Booking\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class Blackout extends Model
{
    use HasUuidV7;

    protected $table = 'blackout';

    protected array $uuidColumns = ['organization_id', 'resource_id', 'facility_unit_id', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
