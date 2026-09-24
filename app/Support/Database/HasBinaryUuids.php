<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;

/**
 * Declare `protected array $uuidColumns = ['organization_id', ...]` on the model. Those columns
 * (plus a uuid primary key when using HasUuidV7) are BINARY(16) in MySQL and canonical strings
 * everywhere in PHP. Queries built through the model accept canonical strings.
 */
trait HasBinaryUuids
{
    public function initializeHasBinaryUuids(): void
    {
        foreach ($this->getUuidColumns() as $column) {
            $this->casts[$column] = BinaryUuid::class;
        }
    }

    /** @return list<string> */
    public function getUuidColumns(): array
    {
        return array_values(array_unique($this->uuidColumns ?? []));
    }

    public function newEloquentBuilder($query): Builder
    {
        return new UuidBuilder($query);
    }
}
