<?php

namespace App\Domain\Booking\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use App\Support\Money\MoneyString;

class BookingItem extends Model
{
    use HasUuidV7;

    protected $table = 'booking_item';

    protected array $uuidColumns = ['booking_id', 'resource_id'];

    protected function casts(): array
    {
        return [
            'slot_start' => 'immutable_datetime',
            'slot_end' => 'immutable_datetime',
            'unit_price' => MoneyString::class,
            'line_total' => MoneyString::class,
            'qty' => 'integer',
            'whole_resource' => 'boolean',
        ];
    }
}
