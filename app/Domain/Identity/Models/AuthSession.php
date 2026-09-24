<?php

namespace App\Domain\Identity\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per issued (access, refresh) token pair. Refresh rotation creates a new row. */
class AuthSession extends Model
{
    use HasUuidV7;

    protected $table = 'session';

    protected array $uuidColumns = ['user_account_id', 'device_id', 'replaced_by_session_id'];

    protected $hidden = ['refresh_token_hash', 'access_token_hash'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime',
            'access_expires_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'user_account_id');
    }
}
