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
