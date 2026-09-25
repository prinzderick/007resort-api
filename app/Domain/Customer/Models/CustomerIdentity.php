<?php

namespace App\Domain\Customer\Models;

use App\Domain\Identity\Models\Customer;
use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A linked social provider account of a customer. Never holds provider tokens. */
class CustomerIdentity extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'customer_identity';

    protected array $uuidColumns = ['customer_id'];

    protected function casts(): array
    {
        return ['email_verified_at_link' => 'datetime', 'linked_at' => 'datetime', 'last_login_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        $f = fn ($d) => $d?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $this->id, 'provider' => $this->provider, 'email' => $this->email_at_link, 'emailVerified' => $this->email_verified_at_link !== null,
            'avatarUrl' => $this->avatar_url, 'linkedAt' => $f($this->linked_at), 'lastLoginAt' => $f($this->last_login_at),
        ];
    }
}
