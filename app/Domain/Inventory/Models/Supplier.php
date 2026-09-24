<?php

namespace App\Domain\Inventory\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class Supplier extends Model
{
    use HasUuidV7;

    protected $table = 'supplier';

    protected array $uuidColumns = ['organization_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
