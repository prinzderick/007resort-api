<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Models\Device;
use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Authorization rules of the contract's private channels (api/realtime.md §2). */
class RealtimeAccess
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    public function ownDevice(string $deviceId): bool
    {
        return RequestContext::deviceId() !== null && RequestContext::deviceId() === Ids::normalize($deviceId);
    }

    public function siteStatus(): bool
    {
        $staff = RequestContext::staffId();
        if ($staff !== null && ($this->permissions->can($staff, 'config.manage') || $this->permissions->can($staff, 'report.view'))) {
            return true;
        }

        return in_array($this->device()?->kind(), ['KDS_SCREEN', 'POS_TERMINAL'], true);
    }

    public function kdsStation(string $stationId): bool
    {
        $staff = RequestContext::staffId();
        $device = $this->device();
        $facility = $this->stationFacility($stationId);
        if ($staff === null || $device === null || $facility === null) {
            return false;
        }

        return $this->permissions->can($staff, 'prep_ticket.view', Scope::facility($facility))
            && ($device->kind() === 'KDS_SCREEN' || $this->deviceAt($device, $facility));
    }

    public function facilityOrders(string $facilityId): bool
    {
        $staff = RequestContext::staffId();
        $device = $this->device();
        if ($staff === null || $device === null || ! Ids::isUuid($facilityId)) {
            return false;
        }
        $facilityId = Ids::normalize($facilityId);
        try {
            $ok = $this->permissions->can($staff, 'order.view', Scope::facility($facilityId)) || $this->permissions->can($staff, 'order.create', Scope::facility($facilityId));
        } catch (ModelNotFoundException) {
            return false;
        }

        return $ok && $this->deviceAt($device, $facilityId);
    }

    private function device(): ?Device
    {
        return ($id = RequestContext::deviceId()) ? Device::query()->find($id) : null;
    }

    /** Checked out at the facility (tablets) or homed there (fixed POS / KDS). */
    private function deviceAt(Device $device, string $facilityId): bool
    {
        return $device->facility_unit_id === $facilityId
            || DB::table('tablet_checkout')->where('device_id', Ids::toBinary($device->id))->whereNull('checked_in_at')->where('facility_unit_id', Ids::toBinary($facilityId))->exists();
    }

    /** Station -> facility: the Hospitality KDS table when present, else an operating point of kind STATION. */
    private function stationFacility(string $stationId): ?string
    {
        if (! Ids::isUuid($stationId)) {
            return null;
        }
        $bin = Ids::toBinary($stationId);
        foreach (['kds_station', 'operating_point'] as $table) {
            if (Schema::hasTable($table) && ($row = DB::table($table)->where('id', $bin)->first(['facility_unit_id']))) {
                return Ids::fromBinary($row->facility_unit_id);
            }
        }

        return null;
    }
}
