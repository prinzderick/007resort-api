<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Services\CorrectionService;
use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Http\Paged;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController
{
    public function __construct(private readonly CorrectionService $corrections, private readonly PermissionChecker $checker) {}

    /** GET /attendance?filter[staffId]&filter[from]&filter[to]&filter[status] */
    public function index(Request $request): JsonResponse
    {
        $f = (array) $request->query('filter', []);
        $request->validate(['filter.from' => ['nullable', 'date_format:Y-m-d'], 'filter.to' => ['nullable', 'date_format:Y-m-d'], 'filter.staffId' => ['nullable', 'uuid'], 'filter.status' => ['nullable', 'in:OPEN,CLOSED,NEEDS_REVIEW']]);
        $q = DB::table('attendance_day')->where('organization_id', Ids::toBinary((string) Tenant::organizationId()));
        if (! empty($f['staffId'])) {
            $q->where('staff_id', Ids::toBinary($f['staffId']));
        }
        if (! empty($f['from'])) {
            $q->where('work_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('work_date', '<=', $f['to']);
        }
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        $page = CursorPage::paginate($q, $request, 'work_date', 'desc');
        $names = DB::table('staff')->whereIn('id', $page->items->pluck('staff_id')->unique()->values()->all())->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($s) => [Ids::fromBinary($s->id) => trim($s->first_name.' '.$s->last_name)]);

        return response()->json(Paged::of($page, fn ($d) => self::record($d, $names[Ids::fromBinary($d->staff_id)] ?? null)));
    }

    /** GET /attendance/punches?attendanceDeviceId&from&to  (raw, for diagnostics/audit) */
    public function punches(Request $request): JsonResponse
    {
        $q = DB::table('attendance_punch')->where('organization_id', Ids::toBinary((string) Tenant::organizationId()));
        if ($v = $request->query('attendanceDeviceId')) {
            $q->where('attendance_device_id', Ids::toBinary($v));
        }
        if ($v = $request->query('from')) {
            $q->where('punched_at', '>=', CarbonImmutable::parse($v, 'UTC')->format('Y-m-d H:i:s.u'));
        }
        if ($v = $request->query('to')) {
            $q->where('punched_at', '<', CarbonImmutable::parse($v, 'UTC')->format('Y-m-d H:i:s.u'));
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json(Paged::of($page, fn ($p) => [
            'id' => Ids::fromBinary($p->id), 'attendanceDeviceId' => Ids::fromBinary($p->attendance_device_id), 'terminalUserId' => $p->terminal_user_id,
            'punchedAt' => CarbonImmutable::parse($p->punched_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'), 'verifyMode' => $p->verify_mode, 'direction' => $p->direction, 'rawId' => $p->raw_id,
        ]));
    }

    /** POST /attendance/corrections */
    public function requestCorrection(Request $request): JsonResponse
    {
        $d = $request->validate([
            'staffId' => ['required', 'uuid'], 'workDate' => ['required', 'date_format:Y-m-d'], 'clockIn' => ['nullable', 'date'], 'clockOut' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $c = $this->corrections->request(
            (string) RequestContext::staffId(), $d['staffId'], $d['workDate'],
            isset($d['clockIn']) ? CarbonImmutable::parse($d['clockIn'])->utc() : null, isset($d['clockOut']) ? CarbonImmutable::parse($d['clockOut'])->utc() : null, $d['reason']
        );

        return response()->json($c->toApi(), 201);
    }

    public function corrections(Request $request): JsonResponse
    {
        $q = AttendanceCorrection::query()->where('organization_id', Tenant::organizationId());
        if ($s = $request->query('status')) {
            $q->where('status', strtoupper((string) $s));
        }

        return response()->json(Paged::of(CursorPage::paginate($q, $request, 'id', 'desc'), fn (AttendanceCorrection $c) => $c->toApi()));
    }

    public function approve(Request $request, string $correction): JsonResponse
    {
        return $this->decide($request, $correction, true);
    }

    public function reject(Request $request, string $correction): JsonResponse
    {
        return $this->decide($request, $correction, false);
    }

    private function decide(Request $request, string $id, bool $approve): JsonResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $c = AttendanceCorrection::query()->find($id) ?? throw ApiProblem::notFound('correction_not_found', 'Correction not found.');
        if (! $this->checker->can((string) RequestContext::staffId(), 'staff.clock_correction.approve', Scope::site($c->site_id))) {
            throw ApiProblem::forbidden('permission_denied', 'Missing permission: staff.clock_correction.approve.', ['permission' => 'staff.clock_correction.approve']);
        }

        return response()->json($this->corrections->decide($id, (string) RequestContext::staffId(), $approve, $d['note'] ?? null)->toApi());
    }

    /** @return array<string, mixed> */
    public static function record(object $d, ?string $staffName): array
    {
        $t = fn ($v) => $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => Ids::fromBinary($d->id), 'staffId' => Ids::fromBinary($d->staff_id), 'staffName' => $staffName, 'workDate' => $d->work_date,
            'clockIn' => $t($d->clock_in), 'clockOut' => $t($d->clock_out), 'minutesWorked' => $d->minutes_worked === null ? null : (int) $d->minutes_worked,
            'punchCount' => (int) $d->punch_count, 'source' => $d->source, 'status' => $d->status,
        ];
    }
}
