<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\TabletCheckout;
use App\Domain\Identity\Services\SessionRevoker;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;

class DeviceService
{
    public function __construct(
        private readonly DeviceTokenService $tokens,
        private readonly SessionRevoker $sessions,
        private readonly DeviceCommandService $commands,
    ) {}

    /**
     * Redeem a one-time registration code. Atomic: the code is consumed with a conditional UPDATE, so two devices racing on
     * one code cannot both register. Re-registering the same hardware id rotates its credential (revoked devices cannot).
     *
     * @param  array{name: string, kind: string, hardwareId: string, platform?: ?string, appVersion?: ?string, registrationCode: string}  $d
     * @return array{device: Device, token: string}
     */
    public function register(array $d): array
    {
        $codeHash = RegistrationCodeService::hash($d['registrationCode']);
        $invalid = fn () => ApiProblem::unprocessable('validation_failed', 'The registration code is invalid, expired or already used.', ['registrationCode' => ['Invalid, expired or already used.']]);

        return DB::transaction(function () use ($d, $codeHash, $invalid): array {
            $code = DB::table('device_registration_code')->where('code_hash', $codeHash)->lockForUpdate()->first();
            if ($code === null || $code->used_at !== null || $code->expires_at <= now('UTC')->format('Y-m-d H:i:s.u')) {
                throw $invalid();
            }
            $orgId = Ids::fromBinary($code->organization_id);
            $siteId = Ids::fromBinary($code->site_id);
            $homeFacility = $code->facility_unit_id ? Ids::fromBinary($code->facility_unit_id) : null;

            $device = Device::query()->where('site_id', $siteId)->where('hardware_id', $d['hardwareId'])->lockForUpdate()->first();
            $new = $device === null;
            if ($device !== null && $device->is_revoked) {
                throw ApiProblem::forbidden('device_revoked', 'This device has been revoked and cannot be re-registered.');
            }
            if ($new) {
                $device = Device::create([
                    'organization_id' => $orgId, 'site_id' => $siteId, 'facility_unit_id' => $homeFacility,
                    'device_type' => Device::KIND_TO_TYPE[$d['kind']], 'mode' => $d['mode'] ?? Device::defaultMode($d['kind']), 'name' => $d['name'], 'hardware_id' => $d['hardwareId'],
                    'platform' => $d['platform'] ?? null, 'app_version' => $d['appVersion'] ?? null,
                ]);
            } else {
                $device->forceFill([
                    'name' => $d['name'], 'device_type' => Device::KIND_TO_TYPE[$d['kind']], 'mode' => $d['mode'] ?? $device->mode ?? Device::defaultMode($d['kind']), 'platform' => $d['platform'] ?? $device->platform,
                    'app_version' => $d['appVersion'] ?? $device->app_version, 'row_version' => $device->row_version + 1,
                ] + ($homeFacility ? ['facility_unit_id' => $homeFacility] : []))->save();
            }
            $createdBy = $code->created_by ? Ids::fromBinary($code->created_by) : null;
            $token = $this->tokens->issue($device, $createdBy);

            DB::table('device_registration_code')->where('id', $code->id)->update(['used_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'used_by_device_id' => Ids::toBinary($device->id)]);

            Audit::record(
                $new ? 'device.register' : 'device.reregister', 'Device', $device->id,
                new: ['deviceId' => $device->id, 'name' => $device->name, 'kind' => $d['kind'], 'mode' => $device->mode, 'hardwareId' => $d['hardwareId']],
                organizationId: $orgId, siteId: $siteId, actorStaffId: $createdBy, facilityUnitId: $homeFacility, deviceId: $device->id,
            );
            Outbox::record('DeviceRegistered', 'Device', $device->id, ['deviceId' => $device->id, 'name' => $device->name, 'kind' => $d['kind'], 'mode' => $device->mode, 'hardwareId' => $d['hardwareId']], (int) $device->row_version, $orgId, $siteId, $homeFacility);

            return ['device' => $device->refresh(), 'token' => $token];
        });
    }

    /** Revoke: credential dead immediately, bound staff sessions revoked, open checkout force-closed, REVOKE command queued. Idempotent. */
    public function revoke(Device $device): Device
    {
        $actor = RequestContext::staffId();

        return DB::transaction(function () use ($device, $actor): Device {
            $d = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ($d->is_revoked) {
                return $d;
            }
            $now = now('UTC')->format('Y-m-d H:i:s.u');
            $d->forceFill(['is_revoked' => 1, 'is_active' => 0, 'revoked_at' => $now, 'row_version' => $d->row_version + 1])->save();
            DB::table('device_registration')->where('device_id', Ids::toBinary($d->id))->whereNull('revoked_at')->update(['revoked_at' => $now, 'revoked_reason' => 'device_revoked']);
            $revokedSessions = $this->sessions->revokeDevice($d->id, 'device_revoked');
            TabletCheckout::query()->where('device_id', $d->id)->whereNull('checked_in_at')
                ->update(['checked_in_at' => $now, 'checked_in_by' => $actor ? Ids::toBinary($actor) : null, 'status' => 'FORCED', 'closing_note' => 'Device revoked']);
            $this->commands->issue($d, 'REVOKE', []);

            Audit::record('device.revoke', 'Device', $d->id, old: ['status' => 'ACTIVE'], new: ['status' => 'REVOKED', 'sessionsRevoked' => $revokedSessions], facilityUnitId: $d->facility_unit_id, deviceId: null);
            Outbox::record('DeviceRevoked', 'Device', $d->id, ['deviceId' => $d->id], (int) $d->row_version, facilityId: $d->facility_unit_id);

            return $d->refresh();
        });
    }

    public function activeCheckout(string $deviceId): ?TabletCheckout
    {
        return TabletCheckout::query()->where('device_id', $deviceId)->whereNull('checked_in_at')->first();
    }

    /** Contract `Device`. @return array<string, mixed> */
    public function present(Device $d, ?TabletCheckout $checkout = null): array
    {
        $checkout ??= $this->activeCheckout($d->id);

        return [
            'id' => $d->id,
            'name' => $d->name,
            'kind' => $d->kind(),
            'mode' => $d->mode ?? Device::defaultMode($d->kind()),
            'status' => $d->status(),
            'facilityId' => $checkout && $checkout->checked_in_at === null ? $checkout->facility_unit_id : null,
            'homeFacilityId' => $d->facility_unit_id,
            'platform' => $d->platform,
            'appVersion' => $d->app_version,
            'lastSeenAt' => $d->last_seen_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'checkout' => $checkout?->toApi(),
            'rowVersion' => (int) $d->row_version,
        ];
    }
}
