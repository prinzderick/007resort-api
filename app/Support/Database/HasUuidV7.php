<?php

namespace App\Support\Database;

use App\Support\Ids;

/**
 * UUIDv7 primary key stored as BINARY(16), exposed as canonical string. Generated on create
 * (client-supplied ids are kept — needed when syncing rows created on the other node).
 */
trait HasUuidV7
{
    use HasBinaryUuids {
        getUuidColumns as protected traitUuidColumns;
    }

    public function initializeHasUuidV7(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    public static function bootHasUuidV7(): void
    {
        static::creating(function ($model): void {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Ids::uuid7();
            }
        });
    }

    public function getUuidColumns(): array
    {
        return array_values(array_unique([$this->getKeyName(), ...$this->traitUuidColumns()]));
    }
}
