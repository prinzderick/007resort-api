<?php

namespace App\Domain\Inventory\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class RentalAsset extends Model
{
    use HasUuidV7;

    protected $table = 'rental_asset';

    protected array $uuidColumns = ['organization_id', 'item_id', 'location_id', 'issued_reference_id', 'issued_by'];
}
