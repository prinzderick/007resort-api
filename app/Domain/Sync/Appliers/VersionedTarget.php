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
     */
    public function __construct(
        public readonly string $table,
        public readonly string $category,
        public readonly array $columns,
        public readonly string $versionColumn = 'row_version',
    ) {}
}
