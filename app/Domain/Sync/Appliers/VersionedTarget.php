<?php

namespace App\Domain\Sync\Appliers;

/**
 * Describes a two-way-editable table for version-checked sync apply.
 *
 * @phpstan-type Col array{0: string, 1: 'string'|'int'|'bool'|'uuid'|'decimal'|'json'|'datetime'}
 */
final class VersionedTarget
{
    /**
     * @param  string  $table  e.g. 'facility_unit'
     * @param  'CONFIGURATION'|'PERMISSION'  $category  conflict category raised on a stale version
     * @param  array<string, array{0: string, 1: string}>  $columns  payload key (camelCase) => [column, type]. ONLY these can be changed by sync
     * @param  string  $versionColumn  the row's optimistic-lock counter (the domain's `row_version`)
     * @param  string  $keyColumn  the column the event's entityId addresses (default `id`; e.g. `organization_id`, `public_id`, `entity_id`)
     * @param  bool  $upsert  a v1 event for a row that does not exist yet INSERTS it from the mapped columns (creation sync)
     * @param  ?\Closure  $creator  (InboundEvent, array $changes): void — custom creation for a v1 event on a missing row (used instead of the generic insert)
     * @param  bool  $lenient  payload keys that are not mapped columns are ignored (snapshot payloads) instead of failing the event; only mapped columns are ever written
     * @param  ?\Closure  $after  (InboundEvent, array $changes): void — extra work after a create/update was applied (child rows); keys it owns need no column mapping
     */
    public function __construct(
        public readonly string $table,
        public readonly string $category,
        public readonly array $columns,
        public readonly string $versionColumn = 'row_version',
        public readonly string $keyColumn = 'id',
        public readonly bool $upsert = false,
        public readonly ?\Closure $creator = null,
        public readonly ?\Closure $after = null,
        public readonly bool $lenient = false,
    ) {}
}
