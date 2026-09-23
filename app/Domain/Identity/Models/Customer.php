<?php

namespace App\Domain\Identity\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class Customer extends Model
{
    use HasUuidV7;

    protected $table = 'customer';

    protected array $uuidColumns = ['organization_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'deleted_at' => 'datetime'];
    }
}
