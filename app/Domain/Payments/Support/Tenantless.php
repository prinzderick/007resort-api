<?php

namespace App\Domain\Payments\Support;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Resolves organization / site of a facility from data (webhooks and queue jobs have no request context). */
final class Tenantless
{
    /** @return array{org: string, site: string} */
    public static function facility(string $facilityId): array
    {
        $f = DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->first(['organization_id', 'site_id']);
        if ($f === null) {
            throw new \DomainException('Unknown facility.');
        }

        return ['org' => Ids::fromBinary($f->organization_id), 'site' => Ids::fromBinary($f->site_id)];
    }
}
