<?php

namespace App\Domain\Identity\Models;

use App\Support\Database\HasBinaryUuids;
use App\Support\Database\Model;
use App\Support\Ids;
use Ramsey\Uuid\Uuid;

/** Local reference table (BIGINT key) + a stable UUID `public_id` for the API. Authorization NEVER branches on `code`/`name`. */
class Role extends Model
{
    use HasBinaryUuids;

    protected $table = 'role';

    protected array $uuidColumns = ['public_id'];

    /** Fixed namespace so the same role code maps to the same UUID on every node and in every client. */
    public const NAMESPACE = '6f0c4a3e-7b1d-4f6a-9c1e-007000000001';

    public static function publicIdFor(string $code): string
    {
        return strtolower(Uuid::uuid5(self::NAMESPACE, $code)->toString());
    }

    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            if (empty($role->public_id)) {
                $role->public_id = $role->code ? self::publicIdFor($role->code) : Ids::uuid7();
            }
        });
    }
}
