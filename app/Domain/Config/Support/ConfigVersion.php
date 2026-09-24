<?php

namespace App\Domain\Config\Support;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Monotonic per-entity version for config rows without a row_version column (call inside the change's transaction). */
final class ConfigVersion
{
    public static function next(string $entityId): int
    {
        $affected = DB::affectingStatement('INSERT INTO config_entity_version (entity_id, version) VALUES (?, 1) ON DUPLICATE KEY UPDATE version = LAST_INSERT_ID(version + 1)', [Ids::toBinary($entityId)]);
        if ($affected === 1) { // fresh insert
            return 1;
        }

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS v')->v;
    }
}
