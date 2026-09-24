<?php

namespace App\Domain\Config\Services;

use App\Domain\Config\Support\ConfigChange;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\DeviceService;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/** PATCH /devices/{id}: name, home facility, operating point, mode, active flag. (Registration/revoke stay in the Devices module.) */
class DeviceAdminService
{
    /** device kind => modes that make sense for it */
    private const MODES = [
        'MOBILE_TABLET' => ['ATTENDANT', 'SUPERVISOR'], 'POS_TERMINAL' => ['POS'], 'KDS_SCREEN' => ['KDS'],
        'ENTRANCE_SCANNER' => ['SPORTS_ENTRANCE', 'SPORTS_STORE'], 'ATTENDANCE_TERMINAL' => ['ATTENDANCE_TERMINAL'], 'SERVER' => [],
    ];

    public function __construct(private readonly DeviceService $devices) {}

    /** @param array<string, mixed> $in name?, facilityId?, operatingPointId?, mode?, active? @return array<string, mixed> */
    public function update(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch): array {
            $d = Ids::isUuid($id) ? Device::query()->where('site_id', Tenant::siteId())->whereKey($id)->lockForUpdate()->first() : null;
            if ($d === null) {
                throw ApiProblem::notFound('not_found', 'Device was not found.');
            }
            Concurrency::assertVersion((int) $d->row_version, $ifMatch, 'device');
            if ($d->is_revoked) {
                throw ApiProblem::conflict('device_revoked', 'A revoked device cannot be edited. Register the hardware again.');
            }
            $old = ['name' => $d->name, 'facilityId' => $d->facility_unit_id, 'operatingPointId' => $d->operating_point_id, 'mode' => $d->mode ?? Device::defaultMode($d->kind()), 'active' => (bool) $d->is_active];
            $new = $old;
            foreach (['name', 'mode', 'active'] as $k) {
                if (array_key_exists($k, $in)) {
                    $new[$k] = $in[$k];
                }
            }
            $facilityChanged = array_key_exists('facilityId', $in) && ($in['facilityId'] === null ? null : Ids::normalize($in['facilityId'])) !== $d->facility_unit_id;
            if (array_key_exists('facilityId', $in)) {
                $new['facilityId'] = $in['facilityId'] === null ? null : Ids::normalize($in['facilityId']);
                if ($new['facilityId'] !== null) {
                    $f = DB::table('facility_unit')->where('id', Ids::toBinary($new['facilityId']))->where('site_id', Ids::toBinary((string) Tenant::siteId()))->whereNull('deleted_at')->first();
                    if ($f === null || ! $f->is_active) {
                        throw ApiProblem::unprocessable('validation_failed', 'Unknown or inactive facility.', ['facilityId' => ['Choose an active facility of this site.']]);
                    }
                }
                if ($facilityChanged && $this->devices->activeCheckout($d->id) !== null) {
                    throw ApiProblem::conflict('device_checked_out', 'This device is checked out to a staff member. Check it in before changing its home facility.');
                }
                if ($facilityChanged && ! array_key_exists('operatingPointId', $in)) {
                    $new['operatingPointId'] = null; // the old point belongs to the old facility
                }
            }
            if (array_key_exists('operatingPointId', $in)) {
                $new['operatingPointId'] = $in['operatingPointId'] === null ? null : Ids::normalize($in['operatingPointId']);
            }
            if ($new['mode'] !== null && ! in_array($new['mode'], self::MODES[$d->kind()] ?? [], true)) {
                throw ApiProblem::unprocessable('validation_failed', "Mode {$new['mode']} does not fit a ".$d->kind().' device.', ['mode' => ['Allowed for this device: '.(implode(', ', self::MODES[$d->kind()] ?? []) ?: 'none').'.']]);
            }
            if ($new['operatingPointId'] !== null) {
                $op = DB::table('operating_point')->where('id', Ids::toBinary($new['operatingPointId']))->where('is_active', 1)->first();
                if ($op === null || Ids::fromBinary($op->facility_unit_id) !== $new['facilityId']) {
                    throw ApiProblem::unprocessable('validation_failed', 'The operating point must be an active point of the device\'s home facility.', ['operatingPointId' => ['Choose an operating point of the home facility.']]);
                }
                if ($new['mode'] === 'KDS' && ! DB::table('kds_station')->where('id', $op->id)->exists()) {
                    throw ApiProblem::unprocessable('validation_failed', 'A KDS device needs a KDS station operating point.', ['operatingPointId' => ['Not a KDS station.']]);
                }
            }
            if ($new === $old) {
                return $this->devices->present($d);
            }
            $bin = fn (?string $u) => $u === null ? null : Ids::toBinary($u);
            $set = ['name' => $new['name'], 'mode' => $new['mode'], 'is_active' => $new['active'] ? 1 : 0, 'facility_unit_id' => $bin($new['facilityId']), 'operating_point_id' => $bin($new['operatingPointId'])];
            DB::table('device')->where('id', Ids::toBinary($d->id))->update(array_merge($set, ['row_version' => $d->row_version + 1]));
            $fresh = Device::query()->find($d->id);
            $changed = array_keys(array_filter($new, fn ($v, $k) => $v !== $old[$k], ARRAY_FILTER_USE_BOTH));
            ConfigChange::record('config.device.update', 'Device', $d->id, array_intersect_key($old, array_flip($changed)), array_intersect_key($new, array_flip($changed)),
                'device', array_intersect_key($new, array_flip($changed)), (int) $fresh->row_version, facilityId: $new['facilityId']);

            return $this->devices->present($fresh);
        });
    }
}
