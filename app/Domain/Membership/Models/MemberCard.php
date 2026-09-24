<?php

namespace App\Domain\Membership\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use Carbon\CarbonImmutable;

class MemberCard extends Model
{
    use HasUuidV7;

    public const QR = 'QR';

    public const NFC = 'NFC';

    public const MEMBER_ID = 'MEMBER_ID';

    protected $table = 'member_card';

    public $timestamps = false;

    protected array $uuidColumns = ['membership_id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->card_type,
            'identifier' => $this->identifier,
            'status' => $this->status,
            'issuedAt' => CarbonImmutable::instance($this->issued_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
