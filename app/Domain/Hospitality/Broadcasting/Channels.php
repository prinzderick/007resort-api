<?php

namespace App\Domain\Hospitality\Broadcasting;

use App\Domain\Identity\Models\UserAccount;
use App\Support\Api\Authz;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/**
 * Private channel authorisation (api/realtime.md §2). Called by POST /api/v1/broadcasting/auth with the staff bearer token.
 * A refusal is a clean 403 problem+json (permission_denied). Device rules apply only when a device context exists
 * (X-Device-Token); permission-based checks always apply.
 */
final class Channels
{
    public static function register(): void
    {
        Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['api', 'auth:staff']]);
        self::define();
    }

    /** Channel authorisation callbacks (separate from the route so tests can re-bind them after switching the broadcaster). */
    public static function define(): void
    {
        Broadcast::channel('kds.station.{stationId}', function (UserAccount $user, string $stationId): bool {
            if (! Ids::isUuid($stationId)) {
                return false;
            }
            $st = DB::table('kds_station')->where('id', Ids::toBinary($stationId))->first(['facility_unit_id']);

            return $st !== null
                && Authz::can('prep_ticket.view', Ids::fromBinary($st->facility_unit_id), $user->staff_id)
                && self::deviceAtFacility(Ids::fromBinary($st->facility_unit_id));
        }, ['guards' => ['staff']]);

        Broadcast::channel('facility.{facilityId}.orders', function (UserAccount $user, string $facilityId): bool {
            if (! Ids::isUuid($facilityId)) {
                return false;
            }

            return (Authz::can('order.view', $facilityId, $user->staff_id) || Authz::can('order.create', $facilityId, $user->staff_id))
                && self::deviceAtFacility($facilityId);
        }, ['guards' => ['staff']]);

        Broadcast::channel('device.{deviceId}', function (UserAccount $user, string $deviceId): bool {
            return RequestContext::deviceId() !== null && strtolower(RequestContext::deviceId()) === strtolower($deviceId);
        }, ['guards' => ['staff']]);

        Broadcast::channel('site.status', function (UserAccount $user): bool {
            if (Authz::can('config.manage', null, $user->staff_id) || Authz::can('report.view.all', null, $user->staff_id)) {
                return true;
            }
            $device = RequestContext::deviceId();

            return $device !== null && DB::table('device')->where('id', Ids::toBinary($device))->whereIn('device_type', ['KDS', 'POS', 'TABLET'])->where('is_revoked', 0)->exists();
        }, ['guards' => ['staff']]);
    }

    /** A device checked out somewhere else may not listen to this facility. No device context => permission check only. */
    private static function deviceAtFacility(string $facilityId): bool
    {
        $device = RequestContext::deviceId();
        if ($device === null) {
            return true;
        }
        $fac = DB::table('device')->where('id', Ids::toBinary($device))->value('facility_unit_id');

        return $fac === null || Ids::fromBinary($fac) === Ids::normalize($facilityId);
    }
}
