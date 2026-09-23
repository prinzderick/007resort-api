<?php

namespace App\Domain\Identity\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Staff extends Model
{
    use HasUuidV7;

    protected $table = 'staff';

    protected array $uuidColumns = ['organization_id', 'site_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'deleted_at' => 'datetime'];
    }

    public function account(): HasOne
    {
        return $this->hasOne(UserAccount::class, 'staff_id');
    }

    public function displayName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** ACTIVE | SUSPENDED (inactive) | TERMINATED (soft-deleted) */
    public function status(): string
    {
        return $this->deleted_at !== null ? 'TERMINATED' : ($this->is_active ? 'ACTIVE' : 'SUSPENDED');
    }

    /** Contract `StaffMember` (+ a few extra fields). @return array<string, mixed> */
    public function toMember(): array
    {
        return [
            'id' => $this->id,
            'displayName' => $this->displayName(),
            'staffNumber' => $this->staff_number,
            'status' => $this->status(),
            'email' => $this->email,
            'phone' => $this->phone,
            'rowVersion' => (int) $this->row_version,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'siteId' => $this->site_id,
        ];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'staffNumber' => $this->staff_number,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'displayName' => $this->displayName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'organizationId' => $this->organization_id,
            'siteId' => $this->site_id,
            'isActive' => (bool) $this->is_active,
        ];
    }
}
