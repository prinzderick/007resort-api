<?php

namespace App\Domain\Customer\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

/** Read-only credential of the website's "online" channel (`scope = public.read`). Only the SHA-256 hash is stored. */
class ServiceToken extends Model implements AuthenticatableContract
{
    use Authenticatable, HasUuidV7;

    public const SCOPE_PUBLIC_READ = 'public.read';

    public const SCOPE_PUBLIC_CHECKOUT = 'public.checkout';

    public const KNOWN_SCOPES = ['public.read', 'public.checkout', 'customer.social'];

    protected $table = 'service_token';

    protected array $uuidColumns = ['organization_id', 'rotated_from_id'];

    protected $hidden = ['token_hash'];

    /** @return list<string> */
    public function scopes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->scope))));
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }
}
