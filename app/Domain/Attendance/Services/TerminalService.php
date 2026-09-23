<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\StaffBiometricLink;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Register biometric terminals (+ their generic `device` row), issue/rotate device tokens, map terminal user ids to staff. */
class TerminalService
{
    public function __construct(private readonly AttendanceDayBuilder $days) {}

    /**
     * @param  array{serialNumber: string, name: string, facilityId?: ?string, adapter?: string, timeZone?: ?string}  $d
     * @return array{device: AttendanceDevice, token: string} token is shown ONCE (only its SHA-256 is stored)
     */
    public function register(array $d): array
    {
        $org = Tenant::organizationId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No organization in context.');
        $site = Tenant::siteId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No site in context.');
        $token = self::newToken();

        try {
            $device = DB::transaction(function () use ($d, $org, $site, $token): AttendanceDevice {
                $deviceId = Ids::uuid7();
                DB::table('device')->insert([
                    'id' => Ids::toBinary($deviceId), 'organization_id' => Ids::toBinary($org), 'site_id' => Ids::toBinary($site),
                    'facility_unit_id' => empty($d['facilityId']) ? null : Ids::toBinary($d['facilityId']),
                    'device_type' => 'BIOMETRIC_TERMINAL', 'name' => $d['name'],
                ]);
                $dev = AttendanceDevice::create([
                    'organization_id' => $org, 'site_id' => $site, 'device_id' => $deviceId, 'facility_unit_id' => $d['facilityId'] ?? null,
                    'serial_number' => trim($d['serialNumber']), 'name' => $d['name'], 'adapter' => $d['adapter'] ?? 'ZKTECO_ADMS',
                    'time_zone' => $d['timeZone'] ?? $this->days->siteTimeZone($site), 'token_hash' => self::hash($token),
                ]);
                Audit::record('attendance.device.register', 'AttendanceDevice', $dev->id, null, ['serialNumber' => $dev->serial_number, 'name' => $dev->name, 'adapter' => $dev->adapter], facilityUnitId: $d['facilityId'] ?? null);

                return $dev;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::conflict('terminal_serial_taken', 'A terminal with that serial number is already registered.');
        }

        return ['device' => $device, 'token' => $token];
    }

    /** @return array{device: AttendanceDevice, token: string} */
    public function rotateToken(string $id): array
    {
        $token = self::newToken();
        $device = DB::transaction(function () use ($id, $token): AttendanceDevice {
            $dev = AttendanceDevice::query()->lockForUpdate()->find($id) ?? throw ApiProblem::notFound('terminal_not_found', 'Terminal not found.');
            $dev->update(['token_hash' => self::hash($token), 'row_version' => $dev->row_version + 1]);
            Audit::record('attendance.device.rotate_token', 'AttendanceDevice', $dev->id, null, ['serialNumber' => $dev->serial_number]);

            return $dev;
        });

        return ['device' => $device, 'token' => $token];
    }

    public function setStatus(string $id, string $status): AttendanceDevice
    {
        return DB::transaction(function () use ($id, $status): AttendanceDevice {
            $dev = AttendanceDevice::query()->lockForUpdate()->find($id) ?? throw ApiProblem::notFound('terminal_not_found', 'Terminal not found.');
            if ($dev->status !== $status) {
                $old = $dev->status;
                $dev->update(['status' => $status, 'row_version' => $dev->row_version + 1]);
                DB::table('device')->where('id', Ids::toBinary($dev->device_id))->update(['is_active' => $status === 'ACTIVE' ? 1 : 0]);
                Audit::record('attendance.device.'.strtolower($status), 'AttendanceDevice', $dev->id, ['status' => $old], ['status' => $status]);
            }

            return $dev;
        });
    }

    /** Map a terminal user id to a staff member; re-derives attendance for every day that already has punches from that terminal user. */
    public function link(string $staffId, string $attendanceDeviceId, string $terminalUserId): StaffBiometricLink
    {
        $terminalUserId = trim($terminalUserId);

        return DB::transaction(function () use ($staffId, $attendanceDeviceId, $terminalUserId): StaffBiometricLink {
            $dev = AttendanceDevice::query()->find($attendanceDeviceId) ?? throw ApiProblem::notFound('terminal_not_found', 'Terminal not found.');
            $staff = DB::table('staff')->where('id', Ids::toBinary($staffId))->where('organization_id', Ids::toBinary($dev->organization_id))->first(['id', 'site_id']);
            if ($staff === null) {
                throw ApiProblem::notFound('staff_not_found', 'Staff member not found.');
            }
            $existing = StaffBiometricLink::query()->where('attendance_device_id', $attendanceDeviceId)->where('terminal_user_id', $terminalUserId)->lockForUpdate()->first();
            if ($existing !== null && $existing->is_active && $existing->staff_id !== Ids::normalize($staffId)) {
                throw ApiProblem::conflict('terminal_user_taken', 'That terminal user id is already linked to another staff member.');
            }
            $before = $existing?->toApi();
            if ($existing === null) {
                $link = StaffBiometricLink::create(['staff_id' => $staffId, 'attendance_device_id' => $attendanceDeviceId, 'terminal_user_id' => $terminalUserId]);
            } else {
                $existing->update(['staff_id' => $staffId, 'is_active' => true]);
                $link = $existing->refresh();
            }
            Audit::record('attendance.link.set', 'StaffBiometricLink', $link->id, $before, $link->toApi());
            $this->rebuildFor($dev, $staffId, $terminalUserId);

            return $link;
        });
    }

    public function unlink(string $linkId): StaffBiometricLink
    {
        return DB::transaction(function () use ($linkId): StaffBiometricLink {
            $link = StaffBiometricLink::query()->lockForUpdate()->find($linkId) ?? throw ApiProblem::notFound('link_not_found', 'Link not found.');
            if ($link->is_active) {
                $before = $link->toApi();
                $link->update(['is_active' => false]);
                Audit::record('attendance.link.remove', 'StaffBiometricLink', $link->id, $before, $link->refresh()->toApi());
                $dev = AttendanceDevice::query()->find($link->attendance_device_id);
                $this->rebuildFor($dev, $link->staff_id, $link->terminal_user_id);
            }

            return $link;
        });
    }

    private function rebuildFor(AttendanceDevice $dev, string $staffId, string $terminalUserId): void
    {
        $tz = $this->days->siteTimeZone($dev->site_id);
        DB::table('staff')->where('id', Ids::toBinary($staffId))->lockForUpdate()->get(['id']);
        $dates = DB::table('attendance_punch')->where('attendance_device_id', Ids::toBinary($dev->id))->where('terminal_user_id', $terminalUserId)->pluck('punched_at')
            ->map(fn ($t) => $this->days->localDate(CarbonImmutable::parse($t, 'UTC'), $tz))->unique();
        foreach ($dates as $date) {
            $this->days->rebuild($dev->organization_id, $dev->site_id, $staffId, $date, $tz, fresh: true);
        }
    }

    public static function newToken(): string
    {
        return 'atd_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
