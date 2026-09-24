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
        return ['is_active' => 'boolean', 'deleted_at' => 'datetime', 'contact' => 'array', 'opening_hours' => 'array', 'deactivated_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @param  list<string>  $capabilities enabled capability codes */
    public function toApi(array $capabilities = []): array
    {
        return [
            'id' => $this->id,
            'siteId' => $this->site_id,
            'parentId' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind ?? 'GENERAL',
            'status' => $this->is_active ? 'ACTIVE' : 'INACTIVE',
            'active' => (bool) $this->is_active,
            'description' => $this->description,
            'timezone' => $this->timezone,
            'sortOrder' => (int) ($this->sort_order ?? 0),
            'contact' => $this->contact,
            'openingHours' => $this->opening_hours,
            'templateKey' => $this->template_key,
            'capabilities' => $capabilities,
            'rowVersion' => (int) $this->row_version,
        ];
    }
}
