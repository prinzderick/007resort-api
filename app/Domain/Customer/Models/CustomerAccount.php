<?php

namespace App\Domain\Customer\Models;

use App\Domain\Identity\Models\Customer;
use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The online login of a `customer` (separate identity space from staff `user_account`). */
class CustomerAccount extends Model implements AuthenticatableContract
{
    use Authenticatable, HasUuidV7;

    protected $table = 'customer_account';

    protected array $uuidColumns = ['customer_id'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'email_verified_at' => 'datetime', 'locked_until' => 'datetime', 'last_login_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
