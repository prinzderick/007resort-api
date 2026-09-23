<?php

namespace App\Support\Tenancy;

use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the organization/site a write belongs to when the caller didn't pass them:
 * request context (authenticated staff/device) -> config('node.site_id') -> the only site
 * in the DB. Phase 1 is single organization / single site (architecture/03).
 */
final class Tenant
{
    public static function siteId(): ?string
    {
        if ($id = RequestContext::siteId()) {
            return $id;
        }
        if ($id = config('node.site_id')) {
            return Ids::normalize($id);
        }
        $row = DB::table('site')->orderBy('id')->first(['id']);

        return $row ? Ids::fromBinary($row->id) : null;
    }

    public static function organizationId(): ?string
    {
        if ($id = RequestContext::organizationId()) {
            return $id;
        }
        if ($id = config('node.organization_id')) {
            return Ids::normalize($id);
        }
        if ($site = self::siteId()) {
            $row = DB::table('site')->where('id', Ids::toBinary($site))->first(['organization_id']);
            if ($row) {
                return Ids::fromBinary($row->organization_id);
            }
        }
        $row = DB::table('organization')->orderBy('id')->first(['id']);

        return $row ? Ids::fromBinary($row->id) : null;
    }
}
