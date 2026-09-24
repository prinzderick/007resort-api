<?php

namespace App\Domain\Organization\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class Organization extends Model
{
    use HasUuidV7;

    protected $table = 'organization';
}
