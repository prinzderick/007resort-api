<?php

namespace App\Domain\Identity\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserAccount extends Model implements AuthenticatableContract
{
    use Authenticatable, HasUuidV7;

    protected $table = 'user_account';

    protected array $uuidColumns = ['staff_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'locked_until' => 'datetime'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class, 'user_account_id');
    }
}
