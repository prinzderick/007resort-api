<?php

namespace App\Domain\Organization\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

/** Courts, pitches, spa rooms, chairs, PCs. Owned by Organization; the Booking module allocates slots on them. */
class BookableResource extends Model
{
    use HasUuidV7;

    protected $table = 'bookable_resource';

    protected array $uuidColumns = ['organization_id', 'site_id', 'facility_unit_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'deleted_at' => 'datetime'];
    }
}
