<?php

namespace App\Domain\Inventory\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class StockLocation extends Model
{
    use HasUuidV7;

    protected $table = 'stock_location';

    protected array $uuidColumns = ['organization_id', 'site_id', 'facility_unit_id'];

    protected function casts(): array
    {
        return ['allow_negative' => 'boolean', 'is_sale_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
