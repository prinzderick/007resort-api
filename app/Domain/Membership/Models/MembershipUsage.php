<?php

namespace App\Domain\Membership\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class MembershipUsage extends Model
{
    use HasUuidV7;

    protected $table = 'membership_usage';

    public $timestamps = false;

    protected array $uuidColumns = ['membership_id', 'facility_unit_id', 'card_id', 'device_id', 'staff_id'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
