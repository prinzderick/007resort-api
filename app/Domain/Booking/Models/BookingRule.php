<?php

namespace App\Domain\Booking\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class BookingRule extends Model
{
    use HasUuidV7;

    protected $table = 'booking_rule';

    protected array $uuidColumns = ['organization_id', 'resource_id', 'facility_unit_id'];
}
