<?php

namespace App\Domain\Identity\Models;

use App\Support\Database\Model;

/** Local reference table (BIGINT key). Authorization NEVER branches on `code`/`name` — only on permissions. */
class Role extends Model
{
    protected $table = 'role';
}
