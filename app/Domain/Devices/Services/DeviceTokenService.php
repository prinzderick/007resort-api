<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Models\Device;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Device credentials: opaque `r7d_...` secret, only its SHA-256 is stored (device_registration.token_hash). */
class DeviceTokenService
{
    public const PREFIX = 'r7d_';

    public const VALID_YEARS = 5;

    /** Issue a credential for a device; revokes older live ones when $rotate. @return string plaintext token (returned ONCE) */
    public function issue(Device $device, ?string $registeredByStaffId, bool $rotate = true): string
    {
        if ($rotate) {
            DB::table('device_registration')->where('device_id', Ids::toBinary($device->id))->whereNull('revoked_at')
                ->update(['revoked_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'revoked_reason' => 'rotated']);
        }
        $token = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->store($device, $token, $registeredByStaffId);

        return $token;
    }

    /** Store a known token (used by the dev seeder for deterministic DEV-ONLY tokens). */
    public function store(Device $device, string $token, ?string $registeredByStaffId): void
    {
        DB::table('device_registration')->insert([
            'id' => Ids::toBinary(Ids::uuid7()),
            'device_id' => Ids::toBinary($device->id),
            'token_id' => Ids::toBinary(Ids::uuid7()),
            'token_hash' => hash('sha256', $token),
            'registered_by_staff_id' => $registeredByStaffId ? Ids::toBinary($registeredByStaffId) : null,
            'issued_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'expires_at' => now('UTC')->addYears(self::VALID_YEARS)->format('Y-m-d H:i:s.u'),
        ]);
    }

    /** @return array{device: Device, revoked: bool}|null null = unknown / expired credential */
    public function resolve(string $token): ?array
    {
        if (! str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $reg = DB::table('device_registration')->where('token_hash', hash('sha256', $token))->first();
        if ($reg === null || $reg->revoked_at !== null || $reg->expires_at <= now('UTC')->format('Y-m-d H:i:s.u')) {
            return null;
        }
        $device = Device::query()->find(Ids::fromBinary($reg->device_id));
        if ($device === null) {
            return null;
        }

        return ['device' => $device, 'revoked' => (bool) $device->is_revoked || ! $device->is_active];
    }
}
