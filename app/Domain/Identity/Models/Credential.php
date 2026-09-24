<?php

namespace App\Domain\Identity\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class Credential extends Model
{
    use HasUuidV7;

    public const PASSWORD = 'PASSWORD';

    public const PIN = 'PIN';

    public const NFC_CARD = 'NFC_CARD';

    public const TOTP = 'TOTP';

    protected $table = 'credential';

    protected array $uuidColumns = ['user_account_id'];

    protected $hidden = ['credential_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_used_at' => 'datetime'];
    }
}
