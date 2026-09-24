<?php

namespace App\Domain\Membership\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Carbon\CarbonImmutable;

class MembershipStatusHistory extends Model
{
    use HasUuidV7;

    protected $table = 'membership_status_history';

    public $timestamps = false;

    protected array $uuidColumns = ['membership_id', 'actor_staff_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'fromStatus' => $this->from_status,
            'toStatus' => $this->to_status,
            'reason' => $this->reason,
            'source' => $this->source,
            'actorStaffId' => $this->actor_staff_id,
            'occurredAt' => CarbonImmutable::instance($this->occurred_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
