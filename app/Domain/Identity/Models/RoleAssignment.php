<?php

namespace App\Domain\Identity\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleAssignment extends Model
{
    use HasUuidV7;

    public const ORGANIZATION = 'ORGANIZATION';

    public const SITE = 'SITE';

    public const FACILITY_UNIT = 'FACILITY_UNIT';

    protected $table = 'role_assignment';

    protected array $uuidColumns = ['staff_id', 'organization_id', 'site_id', 'facility_unit_id', 'granted_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'deleted_at' => 'datetime', 'granted_at' => 'datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
