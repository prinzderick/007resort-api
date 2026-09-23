<?php

namespace App\Domain\Organization\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacilityUnit extends Model
{
    use HasUuidV7;

    protected $table = 'facility_unit';

    protected array $uuidColumns = ['organization_id', 'site_id', 'parent_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'deleted_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
