<?php

namespace App\Domain\Organization\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class Site extends Model
{
    use HasUuidV7;

    protected $table = 'site';

    protected array $uuidColumns = ['organization_id'];
}
