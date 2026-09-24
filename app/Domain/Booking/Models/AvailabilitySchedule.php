<?php

namespace App\Domain\Booking\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class AvailabilitySchedule extends Model
{
    use HasUuidV7;

    protected $table = 'availability_schedule';

    protected array $uuidColumns = ['resource_id'];
}
