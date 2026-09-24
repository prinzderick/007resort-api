<?php

namespace App\Domain\Ticketing\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entitlement extends Model
{
    use HasUuidV7;

    protected $table = 'entitlement';

    protected array $uuidColumns = ['organization_id', 'site_id', 'booking_id', 'order_id', 'customer_id', 'issued_by'];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'row_version' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EntitlementItem::class, 'entitlement_id')->orderBy('sort_order')->orderBy('id');
    }
}
