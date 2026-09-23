<?php

namespace App\Domain\Ticketing\Services;

use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/** Where is this scan happening? Explicit `facilityId`, else the calling device's current checkout facility. */
final class ScanContext
{
    public static function deviceId(): ?string
    {
        return RequestContext::deviceId();
    }

    public static function facilityId(?string $explicit): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return Ids::normalize($explicit);
        }
        $device = self::deviceId();
        if ($device === null) {
            return null;
        }
        $binding = DB::table('device_binding')->where('device_id', Ids::toBinary($device))->whereNull('unbound_at')->orderByDesc('bound_at')->first(['facility_unit_id']);
        if ($binding) {
            return Ids::fromBinary($binding->facility_unit_id);
        }
        $row = DB::table('device')->where('id', Ids::toBinary($device))->first(['facility_unit_id']);

        return $row?->facility_unit_id ? Ids::fromBinary($row->facility_unit_id) : null;
    }
}
