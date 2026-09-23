<?php

namespace App\Domain\Booking\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use App\Support\Money\MoneyString;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasUuidV7;

    public const HELD = 'HELD';

    public const PENDING_PAYMENT = 'PENDING_PAYMENT';

    public const CONFIRMED = 'CONFIRMED';

    public const RESCHEDULED = 'RESCHEDULED';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    public const EXPIRED = 'EXPIRED';

    protected $table = 'booking';

    protected array $uuidColumns = [
        'organization_id', 'site_id', 'facility_unit_id', 'resource_id', 'customer_id', 'membership_id',
        'order_id', 'entitlement_id', 'created_by', 'device_id',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'total' => MoneyString::class,
            'amount_paid' => MoneyString::class,
            'cancellation_fee' => MoneyString::class,
            'quantity' => 'integer',
            'whole_resource' => 'boolean',
            'reschedule_count' => 'integer',
            'row_version' => 'integer',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookableResource::class, 'resource_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class, 'booking_id');
    }

    public function isHoldLive(): bool
    {
        return $this->hold_expires_at !== null && $this->hold_expires_at->isFuture();
    }
}
