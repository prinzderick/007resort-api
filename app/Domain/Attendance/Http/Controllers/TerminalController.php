<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\StaffBiometricLink;
use App\Domain\Attendance\Services\TerminalService;
use App\Support\Http\CursorPage;
use App\Support\Http\Paged;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerminalController
{
    public function __construct(private readonly TerminalService $terminals) {}

    public function index(Request $request): JsonResponse
    {
        $page = CursorPage::paginate(AttendanceDevice::query()->where('organization_id', Tenant::organizationId()), $request, 'id', 'asc');

        return response()->json(Paged::of($page, fn (AttendanceDevice $d) => $d->toApi()));
    }

    /** POST /attendance/devices. Returns `deviceToken` ONCE. Not idempotency-keyed on purpose: a stored response would persist the secret. */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'serialNumber' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:120'], 'facilityId' => ['nullable', 'uuid'],
            'adapter' => ['sometimes', 'in:ZKTECO_ADMS,JSON_PUSH'], 'timeZone' => ['sometimes', 'timezone:all'],
        ]);
        $r = $this->terminals->register($d);

        return response()->json(['device' => $r['device']->toApi(), 'deviceToken' => $r['token']], 201);
    }

    public function rotateToken(string $device): JsonResponse
    {
        $r = $this->terminals->rotateToken($device);

        return response()->json(['device' => $r['device']->toApi(), 'deviceToken' => $r['token']]);
    }

    public function setStatus(Request $request, string $device): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:ACTIVE,DISABLED']]);

        return response()->json($this->terminals->setStatus($device, $d['status'])->toApi());
    }

    public function links(Request $request): JsonResponse
    {
        $q = StaffBiometricLink::query()->where('is_active', true);
        if ($v = $request->query('attendanceDeviceId')) {
            $q->where('attendance_device_id', $v);
        }
        if ($v = $request->query('staffId')) {
            $q->where('staff_id', $v);
        }

        return response()->json(Paged::of(CursorPage::paginate($q, $request, 'id', 'asc'), fn (StaffBiometricLink $l) => $l->toApi()));
    }

    public function link(Request $request): JsonResponse
    {
        $d = $request->validate(['staffId' => ['required', 'uuid'], 'attendanceDeviceId' => ['required', 'uuid'], 'terminalUserId' => ['required', 'string', 'max:32']]);

        return response()->json($this->terminals->link($d['staffId'], $d['attendanceDeviceId'], $d['terminalUserId'])->toApi(), 201);
    }

    public function unlink(string $link): JsonResponse
    {
        return response()->json($this->terminals->unlink($link)->toApi());
    }
}
