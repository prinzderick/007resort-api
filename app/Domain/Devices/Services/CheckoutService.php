<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\TabletCheckout;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Tablet control (spec §5): device -> staff -> facility -> shift, checkout/return times, one ACTIVE checkout per device — enforced by
 * the database (UNIQUE on the generated active_device_id), so concurrent checkouts cannot both succeed.
 */
class CheckoutService
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    /** @param  array{staffId: string, facilityId: string, shiftId?: ?string, openingFloat?: ?string}  $d */
    public function checkout(Device $device, array $d): TabletCheckout
    {
        $actor = RequestContext::staffId();
        $this->assertDeviceUsable($device);

        $staff = Staff::query()->where('site_id', $device->site_id)->find($d['staffId']) ?? throw ApiProblem::unprocessable('validation_failed', 'Unknown staff member.', ['staffId' => ['Unknown staff member.']]);
        if (! $staff->is_active || $staff->deleted_at !== null) {
            throw ApiProblem::forbidden('permission_denied', 'That staff member is not active.');
        }
        if ($staff->id !== $actor && ! $this->permissions->can($actor, 'staff.manage') && ! $this->permissions->can($actor, 'device.register')) {
            throw ApiProblem::permissionDenied('staff.manage');
        }
        $facility = DB::table('facility_unit')->where('id', Ids::toBinary($d['facilityId']))->where('site_id', Ids::toBinary($device->site_id))->whereNull('deleted_at')->first()
            ?? throw ApiProblem::unprocessable('validation_failed', 'Unknown facility.', ['facilityId' => ['Unknown facility.']]);
        if (! $facility->is_active) {
            throw ApiProblem::unprocessable('validation_failed', 'Facility is inactive.', ['facilityId' => ['Facility is inactive.']]);
        }
        if (! in_array(Ids::normalize($d['facilityId']), $this->permissions->facilityIds($staff->id), true)) {
            throw ApiProblem::forbidden('facility_mismatch', 'The staff member is not assigned to that facility.');
        }

        try {
            return DB::transaction(function () use ($device, $staff, $d, $actor): TabletCheckout {
                // No SELECT ... FOR UPDATE first (gap locks would deadlock two racers): just INSERT; the UNIQUE index decides the winner.
                $c = TabletCheckout::create([
                    'organization_id' => $device->organization_id, 'site_id' => $device->site_id, 'device_id' => $device->id,
                    'staff_id' => $staff->id, 'facility_unit_id' => $d['facilityId'], 'shift_id' => $d['shiftId'] ?? null,
                    'opening_float' => $d['openingFloat'] ?? null, 'checked_out_by' => $actor, 'checked_out_at' => now('UTC'), 'status' => 'ACTIVE',
                ]);
                Audit::record('device.checkout', 'Device', $device->id, new: ['deviceId' => $device->id, 'checkoutId' => $c->id, 'staffId' => $staff->id, 'facilityId' => $d['facilityId'], 'shiftId' => $d['shiftId'] ?? null, 'openingFloat' => $d['openingFloat'] ?? null], facilityUnitId: $d['facilityId'], deviceId: $device->id);

                return $c;
            });
        } catch (QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1062 || $code === 1213) { // lost the race on uq_tc_one_active_per_device (or deadlock-victim)
                $open = TabletCheckout::query()->where('device_id', $device->id)->whereNull('checked_in_at')->sharedLock()->first(); // locking read: sees the winner's commit even under REPEATABLE READ
                if ($open !== null && $open->staff_id === $staff->id && $open->facility_unit_id === Ids::normalize($d['facilityId'])) {
                    return $open; // same request repeated (e.g. retry with a fresh idempotency key): converge
                }
                throw ApiProblem::conflict('concurrency_conflict', 'This device is already checked out.');
            }
            throw $e;
        }
    }

    /** @param  array{closingNote?: ?string, cashSessionId?: ?string}  $d */
    public function checkin(Device $device, array $d): TabletCheckout
    {
        $actor = RequestContext::staffId();

        return DB::transaction(function () use ($device, $d, $actor): TabletCheckout {
            $c = TabletCheckout::query()->where('device_id', $device->id)->whereNull('checked_in_at')->lockForUpdate()->first()
                ?? throw ApiProblem::conflict('concurrency_conflict', 'This device is not checked out.');
            if ($c->staff_id !== $actor && ! $this->permissions->can($actor, 'staff.manage') && ! $this->permissions->can($actor, 'device.register')) {
                throw ApiProblem::permissionDenied('staff.manage');
            }
            $c->forceFill([
                'checked_in_at' => now('UTC'), 'checked_in_by' => $actor, 'closing_note' => $d['closingNote'] ?? null,
                'cash_session_id' => $d['cashSessionId'] ?? null, 'status' => $c->staff_id === $actor ? 'RETURNED' : 'FORCED',
            ])->save();
            Audit::record('device.checkin', 'Device', $device->id, new: ['deviceId' => $device->id, 'checkoutId' => $c->id, 'staffId' => $c->staff_id, 'facilityId' => $c->facility_unit_id, 'closingNote' => $d['closingNote'] ?? null], facilityUnitId: $c->facility_unit_id, deviceId: $device->id);

            return $c->refresh();
        });
    }

    private function assertDeviceUsable(Device $device): void
    {
        if ($device->is_revoked) {
            throw ApiProblem::forbidden('device_revoked', 'This device has been revoked.');
        }
        if (! $device->is_active) {
            throw ApiProblem::forbidden('device_not_registered', 'This device is not active.');
        }
    }
}
