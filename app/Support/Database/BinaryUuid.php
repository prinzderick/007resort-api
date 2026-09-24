<?php

namespace App\Support\Database;

use App\Support\Ids;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * BINARY(16) <-> canonical UUID string. Assigning a canonical / 32-hex string stores 16 bytes;
 * reading always yields the canonical lowercase string. Invalid values throw.
 */
class BinaryUuid implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return strlen($value) === 16 ? Ids::fromBinary($value) : $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a UUID string.");
        }
        if (strlen($value) === 16 && ! Ids::isUuid($value)) {
            return $value; // already raw bytes
        }

        return Ids::toBinary($value);
    }
}
