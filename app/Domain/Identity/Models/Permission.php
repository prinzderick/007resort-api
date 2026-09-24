<?php

namespace App\Domain\Identity\Models;

use App\Support\Database\Model;

class Permission extends Model
{
    protected $table = 'permission';

    public const UPDATED_AT = null;
}
