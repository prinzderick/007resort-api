<?php

namespace App\Support\Tenancy;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

final class FacilityTree
{
    /** @return list<string> the facility and all its ancestors (canonical uuids) */
    public static function selfAndAncestors(string $facilityId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE anc AS (
               SELECT id, parent_id FROM facility_unit WHERE id = ?
               UNION ALL
               SELECT f.id, f.parent_id FROM facility_unit f JOIN anc ON f.id = anc.parent_id
             ) SELECT id FROM anc',
            [Ids::toBinary($facilityId)]
        );

        return array_map(fn ($r) => Ids::fromBinary($r->id), $rows);
    }

    /** @return list<string> the facility and all its descendants (canonical uuids) */
    public static function selfAndDescendants(string $facilityId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE sub AS (
               SELECT id FROM facility_unit WHERE id = ?
               UNION ALL
               SELECT f.id FROM facility_unit f JOIN sub ON f.parent_id = sub.id
             ) SELECT id FROM sub',
            [Ids::toBinary($facilityId)]
        );

        return array_map(fn ($r) => Ids::fromBinary($r->id), $rows);
    }
}
