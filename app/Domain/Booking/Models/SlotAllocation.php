<?php

namespace App\Domain\Booking\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class SlotAllocation extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $table = 'slot_allocation';

    protected array $uuidColumns = ['organization_id', 'site_id', 'resource_id', 'booking_item_id'];

    protected function casts(): array
    {
        return ['slot_start' => 'immutable_datetime', 'slot_end' => 'immutable_datetime', 'hold_expires_at' => 'immutable_datetime', 'unit_no' => 'integer'];
    }
}
