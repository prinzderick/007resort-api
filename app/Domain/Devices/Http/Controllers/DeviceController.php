<?php

namespace App\Domain\Devices\Http\Controllers;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\CheckoutService;
use App\Domain\Devices\Services\DeviceCommandService;
use App\Domain\Devices\Services\DeviceService;
use App\Domain\Devices\Services\RegistrationCodeService;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Http\Etag;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController
{
    public function __construct(
        private readonly DeviceService $devices,
        private readonly CheckoutService $checkouts,
        private readonly DeviceCommandService $commands,
        private readonly PermissionChecker $permissions,
    ) {}

    /** POST /devices/register — public; authenticated only by the one-time registration code. Returns the device token ONCE. */
    public function register(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'kind' => ['required', 'in:'.implode(',', array_keys(Device::KIND_TO_TYPE))],
            'mode' => ['nullable', 'in:'.implode(',', Device::MODES)],
            'hardwareId' => ['required', 'string', 'max:128'],
            'platform' => ['nullable', 'string', 'max:32'],
            'appVersion' => ['nullable', 'string', 'max:32'],
            'registrationCode' => ['required', 'string', 'max:64'],
        ]);
        ['device' => $device, 'token' => $token] = $this->devices->register($d);

        return response()->json(['device' => $this->devices->present($device), 'deviceToken' => $token], 201);
    }

    /** POST /devices/registration-codes — admin issues a one-time code (permission device.register). */
    public function issueCode(Request $request): JsonResponse
    {
        $d = $request->validate(['facilityId' => ['nullable', 'uuid']]);
        if (! empty($d['facilityId']) && ! DB::table('facility_unit')->where('id', Ids::toBinary($d['facilityId']))->whereNull('deleted_at')->exists()) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown facility.', ['facilityId' => ['Unknown facility.']]);
        }
        $out = app(RegistrationCodeService::class)->issue(RequestContext::organizationId(), RequestContext::siteId(), $d['facilityId'] ?? null, RequestContext::staffId());
        Audit::record('device.registration_code.issue', 'Device', Ids::uuid7(), new: ['facilityId' => $d['facilityId'] ?? null, 'expiresAt' => $out['expiresAt']]); // never the code

        return response()->json($out, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $q = Device::query()->where('site_id', Tenant::siteId());
        $filter = (array) $request->query('filter', []);
        match ($filter['status'] ?? null) {
            'REVOKED' => $q->where('is_revoked', 1),
            'ACTIVE' => $q->where('is_revoked', 0)->where('is_active', 1),
            'PENDING' => $q->where('is_revoked', 0)->where('is_active', 0),
            default => null,
        };
        if (! empty($filter['facilityId']) && Ids::isUuid($filter['facilityId'])) {
            $fid = Ids::toBinary($filter['facilityId']);
            $q->where(fn ($w) => $w->where('facility_unit_id', $fid)->orWhereIn('id', fn ($s) => $s->select('device_id')->from('tablet_checkout')->where('facility_unit_id', $fid)->whereNull('checked_in_at')));
        }
        $page = CursorPage::paginate($q, $request, 'name');

        return response()->json($page->toArray(fn (Device $d) => $this->devices->present($d)));
    }

    public function show(string $deviceId): JsonResponse
    {
        $d = $this->authorized($deviceId, allowCheckedOutStaff: true);

        return Etag::json($this->devices->present($d), $d->row_version);
    }

    public function checkout(Request $request, string $deviceId): JsonResponse
    {
        $d = $this->find($deviceId);
        $data = $request->validate([
            'staffId' => ['required', 'uuid'],
            'facilityId' => ['required', 'uuid'],
            'shiftId' => ['nullable', 'uuid'],
            'openingFloat' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
        ]);
        $c = $this->checkouts->checkout($d, $data);

        return response()->json($this->devices->present($d->refresh(), $c));
    }

    public function checkin(Request $request, string $deviceId): JsonResponse
    {
        $d = $this->find($deviceId);
        $data = $request->validate(['closingNote' => ['nullable', 'string', 'max:255'], 'cashSessionId' => ['nullable', 'uuid']]);
        $c = $this->checkouts->checkin($d, $data);

        return response()->json($this->devices->present($d->refresh(), $c));
    }

    /** POST /devices/{id}/status — device heartbeat (device token of THAT device, or an admin). Returns pending commands. */
    public function status(Request $request, string $deviceId): JsonResponse
    {
        $d = $this->authorized($deviceId, allowCheckedOutStaff: false);
        $data = $request->validate([
            'appVersion' => ['required', 'string', 'max:32'],
            'batteryPercent' => ['nullable', 'integer', 'between:0,100'],
            'networkType' => ['nullable', 'in:WIFI,CELLULAR,ETHERNET,NONE'],
            'queuedMutations' => ['nullable', 'integer', 'min:0'],
            'oldestQueuedAt' => ['nullable', 'date'],
            'lastSyncedAt' => ['nullable', 'date'],
            'printerStatus' => ['nullable', 'in:OK,OFFLINE,NO_PAPER,UNKNOWN'],
        ]);
        $d->forceFill(['app_version' => $data['appVersion'], 'last_seen_at' => now('UTC'), 'last_status' => $data])->save();

        return response()->json([
            'serverTime' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'commands' => $this->commands->drain($d),
            'minClientVersion' => (string) (config('node.min_client_version')[$this->clientType($d)] ?? '0.0.0'),
        ]);
    }

    public function commands(string $deviceId): JsonResponse
    {
        $d = $this->authorized($deviceId, allowCheckedOutStaff: false);

        return response()->json(['items' => $this->commands->drain($d)]);
    }

    public function revoke(string $deviceId): JsonResponse
    {
        $d = $this->devices->revoke($this->find($deviceId));

        return response()->json($this->devices->present($d));
    }

    private function find(string $deviceId): Device
    {
        return Ids::isUuid($deviceId)
            ? (Device::query()->where('site_id', Tenant::siteId())->find($deviceId) ?? throw ApiProblem::notFound('not_found', 'Device was not found.'))
            : throw ApiProblem::notFound('not_found', 'Device was not found.');
    }

    /** The device itself (token), an admin (device.register|device.view), or (optionally) the staff member it is checked out to. */
    private function authorized(string $deviceId, bool $allowCheckedOutStaff): Device
    {
        $d = $this->find($deviceId);
        if (RequestContext::deviceId() === $d->id) {
            return $d;
        }
        $staff = RequestContext::staffId();
        if ($staff !== null) {
            if ($this->permissions->can($staff, 'device.register') || $this->permissions->can($staff, 'device.view') || $this->permissions->can($staff, 'device.manage')) {
                return $d;
            }
            if ($allowCheckedOutStaff && ($c = $this->devices->activeCheckout($d->id)) !== null && $c->staff_id === $staff) {
                return $d;
            }
        }
        throw ApiProblem::permissionDenied('device.view');
    }

    private function clientType(Device $d): string
    {
        return match ($d->kind()) {
            'MOBILE_TABLET' => 'mobile', 'POS_TERMINAL' => 'pos', 'KDS_SCREEN' => 'kds', default => 'other',
        };
    }
}
