<?php

namespace App\Domain\Booking\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use App\Support\Money\MoneyString;

class BookableResource extends Model
{
    use HasUuidV7;

    public const MODE_WHOLE = 'WHOLE_RESOURCE';

    public const MODE_CAPACITY = 'INDIVIDUAL_CAPACITY';

    public const MODE_SLOT = 'TIME_SLOT';

    public const STRATEGY_A = 'A_OFFLINE_ALLOCATION';

    public const STRATEGY_B = 'B_ONLINE_AUTHORITY_REQUIRED';

    public const STRATEGY_C = 'C_DISABLE_ONLINE';

    protected $table = 'bookable_resource';

    protected array $uuidColumns = ['organization_id', 'site_id', 'facility_unit_id', 'product_id', 'ticket_type_id'];

    protected function casts(): array
    {
        return [
            'price' => MoneyString::class,
            'whole_price' => MoneyString::class,
            'capacity' => 'integer',
            'slot_minutes' => 'integer',
            'max_slots_per_booking' => 'integer',
            'local_reserve_units' => 'integer',
            'online_stale_after_seconds' => 'integer',
            'allow_whole_resource' => 'boolean',
            'online_bookable' => 'boolean',
            'is_active' => 'boolean',
            'row_version' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** Units a booking may occupy (1..capacity). WHOLE_RESOURCE and TIME_SLOT always have exactly one unit. */
    public function unitCount(): int
    {
        return $this->mode === self::MODE_CAPACITY ? max(1, $this->capacity) : 1;
    }
}
