<?php

namespace App\Domain\Ticketing\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class TicketType extends Model
{
    use HasUuidV7;

    protected $table = 'ticket_type';

    protected array $uuidColumns = ['organization_id', 'site_id', 'facility_unit_id', 'product_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'validity_minutes' => 'integer', 'early_entry_minutes' => 'integer', 'row_version' => 'integer'];
    }
}
