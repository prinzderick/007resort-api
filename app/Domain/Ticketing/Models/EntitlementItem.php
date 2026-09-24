<?php

namespace App\Domain\Ticketing\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class EntitlementItem extends Model
{
    use HasUuidV7;

    public const ACCESS = 'ACCESS';

    public const RENTAL = 'RENTAL';

    public const GOODS = 'GOODS';

    public const SERVICE = 'SERVICE';

    protected $table = 'entitlement_item';

    protected array $uuidColumns = ['entitlement_id', 'facility_unit_id', 'ticket_type_id', 'product_id', 'order_line_id'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'qty' => 'float',
            'qty_redeemed' => 'float',
            'qty_returned' => 'float',
            'qty_inside' => 'float',
        ];
    }

    /** NOT_RELEASED | RELEASED | RETURNED for RENTAL items, null otherwise. */
    public function rentalStatus(): ?string
    {
        if ($this->kind !== self::RENTAL) {
            return null;
        }
        if ($this->qty_returned >= $this->qty) {
            return 'RETURNED';
        }

        return $this->qty_redeemed > 0 ? 'RELEASED' : 'NOT_RELEASED';
    }
}
