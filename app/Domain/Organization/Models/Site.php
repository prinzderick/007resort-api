<?php

namespace App\Domain\Organization\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class Site extends Model
{
    use HasUuidV7;

    protected $table = 'site';

    protected array $uuidColumns = ['organization_id'];

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'name' => $this->name,
            'timezone' => $this->time_zone,
            'currency' => $this->currency ?? 'NGN',
            'address' => $this->address,
        ];
    }
}
